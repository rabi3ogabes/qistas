<?php

use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentDate;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| A date typed for a payment (no time, just "2026-10-21") means that day on the WORKSPACE's own calendar, not the
| server's. The app runs on UTC, so reading it on the server's clock put a payment back-dated to "yesterday" at 02:59
| the next day in Riyadh: the end-of-day close counted it in today's cash. PaymentDate is the one place that turns a
| typed date into a moment, and the same rule stands behind every place a person can type one: the API's contract
| payment, customer payment and early settlement, and the web's contract payment and customer payment forms.
|
| The workspace is in Saudi Arabia (Asia/Riyadh, UTC+3, no daylight saving) unless a test says otherwise: 09:00 there is
| 06:00 UTC, and midnight there is 21:00 UTC the evening before.
*/

/** @return list<string> */
function pdChannels(): array
{
    return ['api contract payment', 'api customer payment', 'api early settlement', 'web contract payment', 'web customer payment'];
}

/** @return list<string> the channels whose date field also takes a time (the web's customer payment takes a date alone) */
function pdTimedChannels(): array
{
    return array_values(array_filter(pdChannels(), fn (string $channel) => ! str_contains($channel, 'web customer')));
}

/**
 * An owner of a workspace whose clock is [$timezone], with the features the channels need switched on.
 *
 * @return array{0: User, 1: Tenant}
 */
function pdWorkspace(string $country = 'SA', string $timezone = 'Asia/Riyadh'): array
{
    switchOn(Feature::PaymentTargeting);
    switchOn(Feature::EarlySettlement);

    return owner(['country' => $country, 'timezone' => $timezone]);
}

/**
 * Types [$paidAt] into one of the channels, to pay the first instalment (100.00) of a fresh contract.
 *
 * @return array{0: TestResponse, 1: Contract}
 */
function pdPay(string $channel, User $owner, Tenant $tenant, string $paidAt): array
{
    $test = test();
    $contract = openContract($tenant);
    $fields = ['amount' => '100.00', 'method' => 'cash', 'paid_at' => $paidAt];
    str_starts_with($channel, 'api') ? Sanctum::actingAs($owner, ['app']) : $test->actingAs($owner);

    $response = match ($channel) {
        'api contract payment' => $test->postJson("/api/v1/contracts/{$contract->id}/payments", $fields),
        'api customer payment' => $test->postJson("/api/v1/customers/{$contract->customer_id}/payments", $fields),
        'api early settlement' => $test->postJson("/api/v1/contracts/{$contract->id}/early-settlement", [
            'payoff' => (string) $test->getJson("/api/v1/contracts/{$contract->id}/early-settlement")->assertOk()->json('data.payoff'),
            'method' => 'cash', 'paid_at' => $paidAt,
        ]),
        'web contract payment' => $test->post(route('app.contracts.payments.store', $contract), $fields + ['idempotency_key' => 'pd-'.uniqid()]),
        'web customer payment' => $test->post("/app/customers/{$contract->customer_id}/payment", $fields),
    };

    return [$response, $contract];
}

function pdAccepted(string $channel, TestResponse $response): void
{
    str_starts_with($channel, 'api') ? $response->assertSuccessful() : $response->assertRedirect()->assertSessionHasNoErrors();
}

function pdRefused(string $channel, TestResponse $response): void
{
    str_starts_with($channel, 'api')
        ? $response->assertStatus(422)->assertJsonValidationErrors('paid_at', 'error.fields')
        : $response->assertSessionHasErrors('paid_at');
}

/** When the contract's one payment says it was received, as stored (UTC). */
function pdStored(Tenant $tenant, Contract $contract): string
{
    return asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'payment')->sole()->paid_at->format('Y-m-d H:i:s'));
}

describe('a date typed for a payment', function () {
    it('is the last second of that day on the workspace’s calendar when it is a past day', function (string $channel) {
        $this->travelTo('2026-10-22 06:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-10-21');

        pdAccepted($channel, $response);
        // 23:59:59 on the 21st in Riyadh is 20:59:59 UTC: still the 21st there, not 02:59 on the 22nd.
        expect(pdStored($tenant, $contract))->toBe('2026-10-21 20:59:59');
    })->with(pdChannels());

    it('is accepted at any hour when it is the workspace’s today, and means now', function (string $channel) {
        // 01:00 on the 22nd in Riyadh, but still the evening of the 21st in UTC.
        $this->travelTo('2026-10-21 22:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-10-22');

        pdAccepted($channel, $response);
        expect(pdStored($tenant, $contract))->toBe('2026-10-21 22:00:00');
    })->with(pdChannels());

    it('is refused when it is after the workspace’s today', function (string $channel) {
        $this->travelTo('2026-10-21 22:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-10-23');

        pdRefused($channel, $response);
        expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(0);
    })->with(pdChannels());

    it('says why with the message the ledger gives', function () {
        $this->travelTo('2026-10-21 22:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response] = pdPay('api contract payment', $owner, $tenant, '2026-10-23');

        $response->assertJsonPath('error.fields.paid_at.0', __('A payment cannot be dated in the future.'));
    });
});

describe('a date and time typed for a payment', function () {
    it('is refused when it is later than now', function (string $channel) {
        $this->travelTo('2026-10-22 06:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-10-22 12:30:00');

        pdRefused($channel, $response);
        expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(0);
    })->with(pdTimedChannels());

    it('is kept as typed when it is earlier than now', function () {
        $this->travelTo('2026-10-22 06:00:00');
        [$owner, $tenant] = pdWorkspace();

        // Without an offset it is read on the server's clock (UTC), as it always was: the app and the web forms send a
        // date alone, and a client that sends a time says which zone it means.
        [$response, $contract] = pdPay('api contract payment', $owner, $tenant, '2026-10-22 02:15:00');

        pdAccepted('api contract payment', $response);
        expect(pdStored($tenant, $contract))->toBe('2026-10-22 02:15:00');
    });
});

describe('a date and time typed with an offset', function () {
    it('is stored as the instant it names, never as its digits', function (string $channel, string $typed, string $stored) {
        $this->travelTo('2026-10-22 06:00:00');
        [$owner, $tenant] = pdWorkspace();

        [$response, $contract] = pdPay($channel, $owner, $tenant, $typed);

        pdAccepted($channel, $response);
        expect(pdStored($tenant, $contract))->toBe($stored);
    })->with(pdTimedChannels())->with([
        // 22:30 in Riyadh is 19:30 UTC, still the 21st there; its digits read as UTC would be 01:30 on the 22nd in Riyadh.
        'an integration in Riyadh' => ['2026-10-21T22:30:00+03:00', '2026-10-21 19:30:00'],
        // Noon on the 21st fourteen hours behind UTC is 02:00 on the 22nd in UTC: today in Riyadh, not the 21st.
        'fourteen hours behind' => ['2026-10-21T12:00:00-14:00', '2026-10-22 02:00:00'],
        // Its digits are later than now (06:00 UTC); the moment itself, midnight UTC, is not.
        'fourteen hours ahead' => ['2026-10-22T14:00:00+14:00', '2026-10-22 00:00:00'],
        'UTC, written Z' => ['2026-10-21T23:15:00Z', '2026-10-21 23:15:00'],
    ]);

    it('is refused when the instant it names is later than now, however far east its offset', function (string $channel) {
        $this->travelTo('2026-10-22 06:00:00');
        [$owner, $tenant] = pdWorkspace();

        // 20:30 fourteen hours ahead is 06:30 UTC, half an hour from now.
        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-10-22T20:30:00+14:00');

        pdRefused($channel, $response);
        expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(0);
    })->with(pdTimedChannels());
});

describe('a New York workspace', function () {
    it('closes the 25-hour day when the clocks go back at the last second of it', function (string $channel) {
        // 10:00 in New York on Monday 2 November, the morning after the clocks went back.
        $this->travelTo('2026-11-02 15:00:00');
        [$owner, $tenant] = pdWorkspace('US', 'America/New_York');

        [$response, $contract] = pdPay($channel, $owner, $tenant, '2026-11-01');

        pdAccepted($channel, $response);
        expect(pdStored($tenant, $contract))->toBe('2026-11-02 04:59:59');
    })->with(pdChannels());

    it('refuses a day that is already tomorrow there although it is still today in UTC', function (string $channel) {
        // 22:00 on the 21st in New York is 02:00 on the 22nd in UTC.
        $this->travelTo('2026-10-22 02:00:00');
        [$owner, $tenant] = pdWorkspace('US', 'America/New_York');

        [$response] = pdPay($channel, $owner, $tenant, '2026-10-22');

        pdRefused($channel, $response);
    })->with(pdChannels());

    it('takes the workspace’s today as now although the UTC date has moved on', function () {
        $this->travelTo('2026-10-22 02:00:00');
        [$owner, $tenant] = pdWorkspace('US', 'America/New_York');

        [$response, $contract] = pdPay('api contract payment', $owner, $tenant, '2026-10-21');

        pdAccepted('api contract payment', $response);
        expect(pdStored($tenant, $contract))->toBe('2026-10-22 02:00:00');
    });
});

describe('PaymentDate::moment', function () {
    function pdRiyadh(): Tenant
    {
        return Tenant::factory()->create(['country' => 'SA', 'timezone' => 'Asia/Riyadh']);
    }

    it('calls the last day the calendar has a future day, not a past one', function () {
        $now = CarbonImmutable::parse('2026-10-22 06:00:00', 'UTC');

        expect(PaymentDate::isFuture(pdRiyadh(), '9999-12-31', $now))->toBeTrue()
            ->and(PaymentDate::isFuture(pdRiyadh(), '2026-10-23', $now))->toBeTrue()
            ->and(PaymentDate::isFuture(pdRiyadh(), '2026-10-22', $now))->toBeFalse()
            ->and(PaymentDate::isFuture(pdRiyadh(), '2026-10-21', $now))->toBeFalse();
    });

    it('is the last second of a past day on the workspace’s calendar, in UTC', function () {
        $moment = PaymentDate::moment(pdRiyadh(), '2026-10-21', CarbonImmutable::parse('2026-10-22 06:00:00', 'UTC'));

        expect($moment->format('Y-m-d H:i:s'))->toBe('2026-10-21 20:59:59')
            ->and($moment->getTimezone()->getName())->toBe('UTC');
    });

    it('is the moment given for the workspace’s today, even in the first second of that day', function () {
        $tenant = pdRiyadh();
        $midnight = CarbonImmutable::parse('2026-10-21 21:00:00', 'UTC');
        $justBefore = $midnight->subSecond();

        // The first second of the 22nd in Riyadh: the 22nd is today, the 21st is yesterday.
        expect(PaymentDate::moment($tenant, '2026-10-22', $midnight)->equalTo($midnight))->toBeTrue()
            ->and(PaymentDate::moment($tenant, '2026-10-21', $midnight)->format('Y-m-d H:i:s'))->toBe('2026-10-21 20:59:59')
            // The last second of the 21st: the 21st is today, and the 22nd has not begun.
            ->and(PaymentDate::moment($tenant, '2026-10-21', $justBefore)->equalTo($justBefore))->toBeTrue()
            ->and(PaymentDate::moment($tenant, '2026-10-22', $justBefore)->format('Y-m-d H:i:s'))->toBe('2026-10-22 20:59:59');
    });

    it('defaults to the current moment', function () {
        $this->travelTo('2026-10-21 22:00:00');

        expect(PaymentDate::moment(pdRiyadh(), '2026-10-22')->equalTo(now()))->toBeTrue();
    });

    it('reads a date and time as the instant it names, in UTC', function (string $typed, string $utc) {
        $moment = PaymentDate::moment(pdRiyadh(), $typed, CarbonImmutable::parse('2026-10-22 06:00:00', 'UTC'));

        // In UTC, so the row stores the instant: the database keeps a moment's digits, whatever zone they were written in.
        expect($moment->format('Y-m-d H:i:s'))->toBe($utc)
            ->and($moment->getTimezone()->getName())->toBe('UTC');
    })->with([
        'no offset (read as UTC)' => ['2026-10-21 23:30:00', '2026-10-21 23:30:00'],
        'no offset, no seconds' => ['2026-10-21 23:30', '2026-10-21 23:30:00'],
        'Riyadh' => ['2026-10-21T23:30:00+03:00', '2026-10-21 20:30:00'],
        'Z' => ['2026-10-21T23:30:00Z', '2026-10-21 23:30:00'],
        'fourteen hours behind' => ['2026-10-21T10:00:00-14:00', '2026-10-22 00:00:00'],
        'fourteen hours ahead' => ['2026-10-22T01:00:00+14:00', '2026-10-21 11:00:00'],
    ]);

    it('calls a date and time with an offset future only when its instant is', function () {
        $tenant = pdRiyadh();
        $now = CarbonImmutable::parse('2026-10-22 06:00:00', 'UTC');

        expect(PaymentDate::isFuture($tenant, '2026-10-22T19:59:59+14:00', $now))->toBeFalse()
            ->and(PaymentDate::isFuture($tenant, '2026-10-22T20:00:01+14:00', $now))->toBeTrue()
            ->and(PaymentDate::isFuture($tenant, '2026-10-21T15:59:59-14:00', $now))->toBeFalse()
            ->and(PaymentDate::isFuture($tenant, '2026-10-21T16:00:01-14:00', $now))->toBeTrue();
    });

    it('closes 1 November on the New York clock at 04:59:59 UTC the next day', function () {
        $newYork = Tenant::factory()->create(['country' => 'US', 'timezone' => 'America/New_York']);

        $moment = PaymentDate::moment($newYork, '2026-11-01', CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));

        expect($moment->format('Y-m-d H:i:s'))->toBe('2026-11-02 04:59:59');
    });

    it('says whether a typed value is later than the workspace’s today or than now', function () {
        $tenant = pdRiyadh();
        $now = CarbonImmutable::parse('2026-10-21 22:00:00', 'UTC');

        expect(PaymentDate::isFuture($tenant, '2026-10-22', $now))->toBeFalse()
            ->and(PaymentDate::isFuture($tenant, '2026-10-21', $now))->toBeFalse()
            ->and(PaymentDate::isFuture($tenant, '2026-10-23', $now))->toBeTrue()
            ->and(PaymentDate::isFuture($tenant, '2026-10-21 22:00:01', $now))->toBeTrue()
            ->and(PaymentDate::isFuture($tenant, '2026-10-21 22:00:00', $now))->toBeFalse();
    });
});
