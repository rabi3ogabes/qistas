<?php

use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Plan;
use App\Reports\DashboardMetrics;
use Illuminate\Support\Facades\DB;

/*
 * Win Plan PP5 (K3, part 1): land, cars and farm equipment need quarterly, half-yearly, yearly, daily or the shop's own
 * dates, for as long as the deal lasts; and a few days' grace before an instalment counts as late. Behind the
 * flexible_schedules switch; with it off, contracts work exactly as before (weekly, two-weekly, monthly, up to 120).
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::FlexibleSchedules);
});

function scheduleBody(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '0',
        'markup_type' => 'none', 'markup_value' => '0', 'installment_count' => 4, 'frequency' => 'quarterly',
        'start_date' => '2026-10-07', 'first_due_date' => '2026-11-30',
    ], $overrides);
}

/** @return list<array{0: string, 1: string}> due date and amount of each instalment, in order */
function datesOf(Contract $contract): array
{
    return asTenant($contract->tenant, fn () => Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get()
        ->map(fn (Installment $i) => [$i->due_date->format('Y-m-d'), Money($i->amount)])->all());
}

function Money(string $value): string
{
    return number_format((float) $value, 2, '.', '');
}

describe('frequencies', function () {
    it('makes a quarterly plan, keeping the day of the first due date and clamping it in short months', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);

        $id = $this->postJson('/api/v1/contracts', scheduleBody($customer))->assertCreated()
            ->assertJsonPath('data.frequency', 'quarterly')->json('data.id');

        expect(datesOf(Contract::withoutGlobalScopes()->findOrFail($id)))->toBe([
            ['2026-11-30', '300.00'], ['2027-02-28', '300.00'], ['2027-05-30', '300.00'], ['2027-08-30', '300.00'],
        ]);
    });

    it('offers every way a deal is paid', function (string $frequency, string $second) {
        [, $tenant] = apiOwner();
        $id = $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), ['frequency' => $frequency, 'first_due_date' => '2027-01-31', 'installment_count' => 2]))
            ->assertCreated()->json('data.id');

        expect(datesOf(Contract::withoutGlobalScopes()->findOrFail($id))[1][0])->toBe($second);
    })->with([
        'daily' => ['daily', '2027-02-01'],
        'weekly' => ['weekly', '2027-02-07'],
        'every two weeks' => ['biweekly', '2027-02-14'],
        'monthly' => ['monthly', '2027-02-28'],
        'every two months' => ['bimonthly', '2027-03-31'],
        'quarterly' => ['quarterly', '2027-04-30'],
        'every six months' => ['semiannual', '2027-07-31'],
        'yearly' => ['yearly', '2028-01-31'],
    ]);

    it('runs a deal for up to 600 instalments, and no more', function () {
        $tenant = apiOwner()[1];
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $customer = customerIn($tenant);

        $this->postJson('/api/v1/contracts', scheduleBody($customer, ['frequency' => 'monthly', 'installment_count' => 600, 'principal' => '600000.00']))->assertCreated();
        $this->postJson('/api/v1/contracts', scheduleBody($customer, ['frequency' => 'monthly', 'installment_count' => 601, 'principal' => '601000.00']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['installment_count']]]);
    });
});

describe('the shop’s own dates', function () {
    it('takes a list of dates and amounts that add up to the total', function () {
        [, $tenant] = apiOwner();
        $body = scheduleBody(customerIn($tenant), [
            'frequency' => 'custom', 'principal' => '1000.00', 'markup_type' => 'fixed', 'markup_value' => '100.00',
            'installment_count' => null, 'first_due_date' => null,
            'custom_schedule' => [
                ['due_date' => '2026-11-15', 'amount' => '500.00'],
                ['due_date' => '2027-03-01', 'amount' => '350.00'],
                ['due_date' => '2027-06-20', 'amount' => '250.00'],
            ],
        ]);

        $id = $this->postJson('/api/v1/contracts', $body)->assertCreated()
            ->assertJsonPath('data.frequency', 'custom')->assertJsonPath('data.installment_count', 3)->json('data.id');

        expect(datesOf(Contract::withoutGlobalScopes()->findOrFail($id)))->toBe([['2026-11-15', '500.00'], ['2027-03-01', '350.00'], ['2027-06-20', '250.00']]);
    });

    it('says how far the amounts are from the total when they do not add up', function () {
        [, $tenant] = apiOwner();
        $body = scheduleBody(customerIn($tenant), ['frequency' => 'custom', 'principal' => '1000.00', 'custom_schedule' => [
            ['due_date' => '2026-11-15', 'amount' => '500.00'],
            ['due_date' => '2027-03-01', 'amount' => '400.00'],
        ]]);

        $message = $this->postJson('/api/v1/contracts', $body)->assertStatus(422)->json('error.fields.schedule.0');

        expect($message)->toContain('900.00')->toContain('1000.00');
    });

    it('names the row whose date is not after the one before', function () {
        [, $tenant] = apiOwner();
        $body = scheduleBody(customerIn($tenant), ['frequency' => 'custom', 'principal' => '1000.00', 'custom_schedule' => [
            ['due_date' => '2026-11-15', 'amount' => '500.00'],
            ['due_date' => '2026-11-15', 'amount' => '500.00'],
        ]]);

        expect($this->postJson('/api/v1/contracts', $body)->assertStatus(422)->json('error.fields.schedule.0'))->toContain('2');
    });

    it('says in plain words which row is missing something', function (array $row, string $field, string $message) {
        [, $tenant] = apiOwner();
        $body = scheduleBody(customerIn($tenant), ['frequency' => 'custom', 'principal' => '1000.00', 'custom_schedule' => [
            ['due_date' => '2026-11-15', 'amount' => '500.00'],
            $row,
        ]]);

        expect($this->postJson('/api/v1/contracts', $body)->assertStatus(422)->json('error.fields')["custom_schedule.1.{$field}"][0])->toBe($message);
    })->with([
        'no date' => [['due_date' => '', 'amount' => '500.00'], 'due_date', 'Row 2: enter the date.'],
        'not a date' => [['due_date' => '15/01/2027', 'amount' => '500.00'], 'due_date', 'Row 2: enter a real date.'],
        'no amount' => [['due_date' => '2027-01-15', 'amount' => ''], 'amount', 'Row 2: enter the amount.'],
        'not a number' => [['due_date' => '2027-01-15', 'amount' => 'five hundred'], 'amount', 'Row 2: enter the amount as a number, like 250.50.'],
    ]);

    it('previews the shop’s own dates before saving', function () {
        apiOwner();

        $this->postJson('/api/v1/contracts/preview', [
            'principal' => '300.00', 'frequency' => 'custom',
            'custom_schedule' => [['due_date' => '2026-12-01', 'amount' => '100.00'], ['due_date' => '2027-02-01', 'amount' => '200.00']],
        ])->assertOk()->assertJsonPath('data.installments.1', ['number' => 2, 'due_date' => '2027-02-01', 'amount' => '200.00']);
    });
});

describe('grace days', function () {
    it('counts an instalment as late only after its grace days', function () {
        [, $tenant] = apiOwner();
        $id = $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), [
            'frequency' => 'monthly', 'first_due_date' => '2026-10-10', 'installment_count' => 2, 'grace_days' => 5,
        ]))->assertCreated()->assertJsonPath('data.grace_days', 5)->json('data.id');
        $contract = Contract::withoutGlobalScopes()->findOrFail($id);

        $this->travelTo('2026-10-15 12:00:00');
        expect(asTenant($tenant, fn () => Contract::query()->inView('late')->count()))->toBe(0)
            ->and(app(DashboardMetrics::class)->for($tenant)['overdue'])->toBe('0.00');
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.state', 'active');

        $this->travelTo('2026-10-16 12:00:00');
        expect(asTenant($tenant, fn () => Contract::query()->inView('late')->count()))->toBe(1)
            ->and(app(DashboardMetrics::class)->for($tenant)['overdue'])->toBe('600.00');
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.state', 'late');
    });

    it('keeps grace days between 0 and 90', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), ['grace_days' => 91]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['grace_days']]]);
    });
});

describe('with the switch off', function () {
    beforeEach(fn () => DB::table('platform_features')->where('feature_key', 'flexible_schedules')->update(['state' => 'off']));

    it('still makes weekly, two-weekly and monthly plans of up to 120, as before', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), ['frequency' => 'monthly', 'installment_count' => 120, 'principal' => '12000.00']))->assertCreated();
    });

    it('refuses the new kinds of plan, saying the feature is not available', function (array $overrides) {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), $overrides))
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    })->with([
        'quarterly' => [['frequency' => 'quarterly']],
        'more than 120' => [['frequency' => 'monthly', 'installment_count' => 121, 'principal' => '12100.00']],
        'grace days' => [['frequency' => 'monthly', 'grace_days' => 3]],
    ]);
});

describe('on the web with the switch off', function () {
    beforeEach(fn () => DB::table('platform_features')->where('feature_key', 'flexible_schedules')->update(['state' => 'off']));

    it('goes back to the form with a plain message, keeping what was typed', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->from('/app/contracts/create')
            ->post('/app/contracts', scheduleBody($customer))
            ->assertRedirect('/app/contracts/create')
            ->assertSessionHas('error', 'That is not available right now.')
            ->assertSessionHasInput('frequency', 'quarterly');

        expect(asTenant($tenant, fn () => Contract::query()->count()))->toBe(0);
    });

    it('shows the not-available page, never an error, when a page of a switched-off feature is opened', function () {
        [$user] = owner();
        DB::table('platform_features')->where('feature_key', 'members')->update(['state' => 'off']);

        $this->actingAs($user)->get('/app/team')->assertForbidden()->assertSee('That is not available right now.');
    });
});

it('suggests a cash sale when the down payment is the whole price', function () {
    [, $tenant] = apiOwner();

    $message = $this->postJson('/api/v1/contracts', scheduleBody(customerIn($tenant), ['frequency' => 'monthly', 'down_payment' => '1200.00']))
        ->assertStatus(422)->json('error.fields.down_payment.0');

    expect($message)->toContain('cash sale');
});

describe('the web form', function () {
    it('offers every kind of plan, up to 600, with grace days and the shop’s own dates', function () {
        [$user, $tenant] = owner();
        customerIn($tenant);

        $page = $this->actingAs($user)->get('/app/contracts/create')->assertOk();

        foreach (['daily', 'weekly', 'biweekly', 'monthly', 'bimonthly', 'quarterly', 'semiannual', 'yearly', 'custom'] as $frequency) {
            $page->assertSee('<option value="'.$frequency.'"', false);
        }
        $page->assertSee('max="600"', false)->assertSee('name="grace_days"', false)
            ->assertSee('Payment dates')->assertSee('Last payment');
    });

    it('offers only weekly, two-weekly and monthly plans of up to 120 while the switch is off', function () {
        DB::table('platform_features')->where('feature_key', 'flexible_schedules')->update(['state' => 'off']);
        [$user, $tenant] = owner();
        customerIn($tenant);

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()
            ->assertSee('<option value="monthly"', false)->assertSee('<option value="weekly"', false)
            ->assertDontSee('<option value="quarterly"', false)->assertDontSee('<option value="custom"', false)
            ->assertSee('max="120"', false)->assertDontSee('name="grace_days"', false)->assertDontSee('Payment dates');
    });

    it('opens a contract from the dates typed in the form, skipping a row left blank', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->post('/app/contracts', scheduleBody($customer, [
            'frequency' => 'custom', 'principal' => '900.00', 'installment_count' => '', 'first_due_date' => '', 'grace_days' => '3',
            'custom_schedule' => [
                ['due_date' => '2026-11-15', 'amount' => '400.00'],
                ['due_date' => '', 'amount' => ''],
                ['due_date' => '2027-01-15', 'amount' => '500.00'],
            ],
        ]))->assertRedirect();

        $contract = asTenant($tenant, fn () => Contract::query()->sole());
        expect(datesOf($contract))->toBe([['2026-11-15', '400.00'], ['2027-01-15', '500.00']])->and($contract->grace_days)->toBe(3);
    });

    it('names the plan in words on the contract page, with its grace days', function (string $frequency, string $words) {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        $overrides = $frequency === 'custom'
            ? ['frequency' => 'custom', 'custom_schedule' => [['due_date' => '2026-12-01', 'amount' => '1200.00']]]
            : ['frequency' => $frequency];

        $this->actingAs($user)->post('/app/contracts', scheduleBody($customer, [...$overrides, 'grace_days' => '7']))->assertRedirect();
        $contract = asTenant($tenant, fn () => Contract::query()->sole());

        $this->actingAs($user)->get("/app/contracts/{$contract->id}")->assertOk()
            ->assertSee($words)->assertSee('Grace days')->assertSeeInOrder(['Grace days', '7']);
    })->with([
        'quarterly' => ['quarterly', 'Every three months'],
        'the shop’s own dates' => ['custom', 'On dates I choose'],
    ]);

    it('brings back the dates already typed when the form is sent back with a problem', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->from('/app/contracts/create')->post('/app/contracts', scheduleBody($customer, [
            'frequency' => 'custom', 'principal' => '1000.00', 'custom_schedule' => [['due_date' => '2026-11-15', 'amount' => '400.00']],
        ]))->assertRedirect('/app/contracts/create');

        $this->actingAs($user)->get('/app/contracts/create')->assertSee('2026-11-15')->assertSee('400.00')
            ->assertSee('The instalments add up to 400.00, but the total is 1000.00.');
    });
});
