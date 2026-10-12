<?php

use App\Actions\CancelContract;
use App\Actions\ChargeLateFees;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Settings\TenantSettings;
use App\Support\Money;
use OpenSpout\Reader\XLSX\Reader;

/*
| Reports, charts and profit (Win Plan K8, RP14, RP15, CT27; decision D7): a cash-flow forecast for 30, 60 and 90
| days, ageing in five buckets, the collection rate, and profit as money comes in, by month, investor and product,
| by the investor ledger's own rule. One page on the web with charts drawn by the server, the same figures over the
| API for the app, and an Excel file that says what the screen says. Reports are part of Pro.
|
| openContract(): three monthly instalments of 100.00 due 1 Feb, 1 Mar and 1 Apr 2026 (no markup), unless told otherwise.
*/

beforeEach(function () {
    $this->travelTo('2026-01-15 09:00:00');
    switchOn(Feature::Investors);
});

/** An owner on Pro, signed in to the API. */
function reportsOwner(): array
{
    [$owner, $tenant] = apiOwner();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    return [$owner, $tenant];
}

function payTowards(Contract $contract, string $amount): void
{
    app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), $amount, 'cash');
}

function reportData(string $name, array $query = []): array
{
    return test()->getJson("/api/v1/reports/{$name}?".http_build_query($query))->assertOk()->json('data');
}

function sheetsOf(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $bytes);
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    return $sheets;
}

/** A contract of 300.00 with a 10% markup: 330.00 in three instalments of 110.00 from 1 February. */
function markedUp(Tenant $tenant, array $overrides = []): Contract
{
    return openContract($tenant, ['markup_type' => 'percent', 'markup_value' => '10', ...$overrides]);
}

describe('the forecast', function () {
    it('expects what falls due in each window, after what is already paid', function () {
        [, $tenant] = reportsOwner();
        $contract = openContract($tenant);
        payTowards($contract, '40.00');

        $forecast = reportData('forecast');

        expect($forecast['windows'])->toBe(['days_30' => '60.00', 'days_60' => '160.00', 'days_90' => '260.00'])
            ->and($forecast['already_due'])->toBe('0.00')
            ->and($forecast['weeks'])->toHaveCount(13)
            ->and($forecast['weeks'][2])->toBe(['from' => '2026-01-29', 'to' => '2026-02-04', 'amount' => '60.00'])
            // 1 March falls in the seventh week (26 Feb to 4 Mar).
            ->and($forecast['weeks'][6]['amount'])->toBe('100.00');
    });

    it('keeps what is already due apart, and leaves out cancelled and settled contracts', function () {
        [$owner, $tenant] = reportsOwner();
        openContract($tenant);
        $cancelled = openContract($tenant);
        app(CancelContract::class)->handle($cancelled, 'Returned', $owner);
        $settled = openContract($tenant);
        payTowards($settled, '300.00');

        $this->travelTo('2026-02-10 09:00:00');
        $forecast = reportData('forecast');

        expect($forecast['already_due'])->toBe('100.00')
            ->and($forecast['windows']['days_30'])->toBe('100.00');
    });
});

describe('ageing', function () {
    it('puts each late amount in one bucket, counted from the end of the grace days', function () {
        [, $tenant] = reportsOwner();
        switchOn(Feature::FlexibleSchedules);
        // Due 1 Oct, 1 Nov, 1 Dec 2025, 1 Jan, 1 Feb and 1 Mar 2026: 106, 75, 45 and 14 days late, and two not yet due.
        $old = openContract($tenant, ['principal' => '600.00', 'installment_count' => 6, 'start_date' => '2025-09-15', 'first_due_date' => '2025-10-01']);
        // Due 1 Jan with 20 grace days: not late until 22 Jan.
        openContract($tenant, ['principal' => '100.00', 'installment_count' => 1, 'start_date' => '2025-12-15', 'first_due_date' => '2026-01-01', 'grace_days' => 20]);

        $ageing = reportData('ageing');

        expect($ageing['buckets'])->toBe(['current' => '300.00', 'days_1_30' => '100.00', 'days_31_60' => '100.00', 'days_61_90' => '100.00', 'days_90_plus' => '100.00'])
            ->and($ageing['total'])->toBe('700.00')
            ->and($ageing['contracts'][0])->toMatchArray(['id' => $old->id, 'reference' => $old->reference(), 'days_90_plus' => '100.00', 'current' => '200.00', 'total' => '600.00']);
    });
});

describe('profit', function () {
    it('counts profit as money comes in, by month, exactly as the investor ledger does', function () {
        [$owner, $tenant] = reportsOwner();
        $contract = markedUp($tenant);
        $this->travelTo('2026-01-20 10:00:00');
        payTowards($contract, '110.00');
        $this->travelTo('2026-02-20 10:00:00');
        payTowards($contract, '110.00');
        $this->travelTo('2026-03-05 10:00:00');
        payTowards($contract, '55.00');
        $voided = app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), '20.00', 'cash');
        app(VoidTransaction::class)->handle($voided, 'Typed twice', $owner);
        $this->travelTo('2026-03-10 09:00:00');

        $profit = reportData('profit', ['period' => 'last_12_months']);
        $ledger = asTenant($tenant, fn () => Money::fromDatabase(InvestorEntry::where('type', 'profit_share')->sum('amount')));

        expect($profit['total'])->toBe('25.00')->toBe($ledger)
            ->and($profit['from'])->toBe('2025-04-01')
            ->and($profit['to'])->toBe('2026-03-31')
            ->and($profit['months'])->toHaveCount(12)
            ->and(array_slice($profit['months'], -3))->toBe([
                ['month' => '2026-01', 'profit' => '10.00'],
                ['month' => '2026-02', 'profit' => '10.00'],
                ['month' => '2026-03', 'profit' => '5.00'],
            ]);
    });

    it('splits it by investor, net of a partner’s commission to the business', function () {
        [, $tenant] = reportsOwner();
        $partner = asTenant($tenant, fn () => tap((new Investor)->forceFill(['name' => 'Saeed', 'commission_percent' => '20', 'is_main' => false, 'currency' => 'SAR']))->save());
        $contract = markedUp($tenant, ['investor_id' => $partner->id]);
        $this->travelTo('2026-02-02 10:00:00');
        payTowards($contract, '110.00');

        $investors = collect(reportData('profit', ['period' => 'this_year'])['investors'])->keyBy('name');

        expect($investors['Saeed']['profit'])->toBe('8.00')
            ->and($investors['Own capital']['profit'])->toBe('2.00');
    });

    it('splits it by product, by what each item sold for', function () {
        [, $tenant] = reportsOwner();
        switchOn(Feature::ContractItems);
        [$phone, $case] = asTenant($tenant, fn () => [
            tap((new Product)->forceFill(['name' => 'Phone']))->save(),
            tap((new Product)->forceFill(['name' => 'Case']))->save(),
        ]);
        $contract = markedUp($tenant, ['items' => [
            ['name' => 'Phone', 'product_id' => $phone->id, 'quantity' => 1, 'price' => '200.00'],
            ['name' => 'Case', 'product_id' => $case->id, 'quantity' => 2, 'price' => '50.00'],
        ]]);
        $this->travelTo('2026-04-02 10:00:00');
        payTowards($contract, '330.00');

        $products = collect(reportData('profit', ['period' => 'this_year'])['products'])->keyBy('name');

        expect($products['Phone']['profit'])->toBe('20.00')
            ->and($products['Case']['profit'])->toBe('10.00');
    });

    it('says how much of what fell due in the period has been collected', function () {
        [, $tenant] = reportsOwner();
        openContract($tenant);
        $this->travelTo('2026-03-10 09:00:00');
        payTowards(Contract::withoutGlobalScopes()->first(), '150.00');

        // Due in February and March: 200.00, of which 150.00 is paid.
        expect(reportData('profit', ['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-03-31'])['collection_rate'])->toBe('75.0');
    });

    it('counts the late fees apart, charity apart from the business', function () {
        [, $tenant] = reportsOwner();
        switchOn(Feature::LateFees);
        TenantSettings::for($tenant)->set('late_fees.amount', '10');
        TenantSettings::for($tenant)->set('late_fees.charity', true);
        $contract = openContract($tenant);
        $this->travelTo('2026-02-05 09:00:00');
        app(ChargeLateFees::class)->handle(Tenant::find($tenant->id));
        payTowards($contract, '110.00');

        expect(reportData('profit', ['period' => 'this_year'])['late_fees'])->toBe(['shop' => '0.00', 'charity' => '10.00']);
    });
});

describe('filters', function () {
    it('narrow every report to one investor, one product and the period', function () {
        [, $tenant] = reportsOwner();
        switchOn(Feature::ContractItems);
        $partner = asTenant($tenant, fn () => tap((new Investor)->forceFill(['name' => 'Saeed', 'commission_percent' => '0', 'is_main' => false, 'currency' => 'SAR']))->save());
        [$phone, $case] = asTenant($tenant, fn () => [tap((new Product)->forceFill(['name' => 'Phone']))->save(), tap((new Product)->forceFill(['name' => 'Case']))->save()]);
        // Sold 200.00 of phone and 100.00 of case: the phone keeps two thirds of the contract's profit.
        $theirs = markedUp($tenant, ['investor_id' => $partner->id, 'items' => [
            ['name' => 'Phone', 'product_id' => $phone->id, 'quantity' => 1, 'price' => '200.00'],
            ['name' => 'Case', 'product_id' => $case->id, 'quantity' => 1, 'price' => '100.00'],
        ]]);
        $ours = markedUp($tenant);
        $this->travelTo('2026-02-02 10:00:00');
        payTowards($theirs, '110.00');
        payTowards($ours, '110.00');

        expect(reportData('profit', ['period' => 'this_year'])['total'])->toBe('20.00')
            ->and(reportData('profit', ['period' => 'this_year', 'investor_id' => $partner->id])['total'])->toBe('10.00')
            ->and(reportData('profit', ['period' => 'this_year', 'product_id' => $phone->id])['total'])->toBe('6.67')
            ->and(reportData('profit', ['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'])['total'])->toBe('0.00')
            ->and(reportData('forecast', ['investor_id' => $partner->id])['windows']['days_90'])->toBe('220.00')
            ->and(reportData('ageing', ['product_id' => $phone->id])['total'])->toBe('220.00');
    });

    it('refuses an investor or a product of another workspace, and a period that ends before it starts', function () {
        reportsOwner();
        [, $other] = owner();
        $foreign = asTenant($other, fn () => tap((new Product)->forceFill(['name' => 'Theirs']))->save());

        $this->getJson('/api/v1/reports/profit?product_id='.$foreign->id)->assertStatus(422);
        $this->getJson('/api/v1/reports/profit?period=custom&from=2026-03-01&to=2026-02-01')->assertStatus(422);
    });
});

describe('who sees them', function () {
    it('shows a Free workspace the upgrade sheet instead', function () {
        apiOwner();

        $this->getJson('/api/v1/reports/profit')->assertStatus(402)->assertJsonPath('error.code', 'feature_locked');
    });

    it('keeps them from collectors', function () {
        [, $tenant] = reportsOwner();
        apiMember('collector', $tenant);

        $this->getJson('/api/v1/reports/forecast')->assertForbidden();
    });
});

describe('on the web', function () {
    it('draws the three reports with their charts', function () {
        [$owner, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $contract = markedUp($tenant);
        $this->travelTo('2026-02-02 10:00:00');
        payTowards($contract, '110.00');

        $this->actingAs($owner)->get('/app/reports')->assertOk()
            ->assertSee('Expected in the next 30 days')->assertSee('Ageing')->assertSee('Profit as money comes in')
            ->assertSee('<svg', false)->assertSee('role="img"', false)
            ->assertSee(route('app.reports.export'), false);
    });

    it('is in the menu for the people who see it', function () {
        [$owner, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $collector = memberAs('collector', $tenant);

        $this->actingAs($owner)->get(route('app.dashboard'))->assertSee(route('app.reports'), false);
        $this->actingAs($collector)->get(route('app.dashboard'))->assertDontSee(route('app.reports'), false);
        $this->actingAs($collector)->get('/app/reports')->assertForbidden();
    });

    it('shows a Free workspace what Pro would give it, with the way to upgrade', function () {
        [$owner] = owner();

        $this->actingAs($owner)->get('/app/reports')->assertOk()
            ->assertSee('Reports come with Pro')->assertDontSee('Expected in the next 30 days')->assertSee('/app/billing', false);
    });

    it('lets a viewer read the reports, and only those who may export download them', function () {
        [, $tenant] = reportsOwner();
        $viewer = memberAs('viewer', $tenant);

        $this->actingAs($viewer)->get('/app/reports')->assertOk()->assertDontSee(route('app.reports.export'), false);
        $this->actingAs($viewer)->get('/app/reports/export')->assertForbidden();
    });

    it('exports what the screen shows', function () {
        [$owner, $tenant] = reportsOwner();
        $contract = markedUp($tenant);
        $this->travelTo('2026-02-02 10:00:00');
        payTowards($contract, '110.00');
        $profit = reportData('profit', ['period' => 'this_year']);
        $forecast = reportData('forecast');

        $response = $this->actingAs($owner)->get('/app/reports/export?period=this_year')->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
        $sheets = sheetsOf((string) $response->getContent());

        expect(array_keys($sheets))->toBe(['Forecast', 'Ageing', 'Profit by month', 'Profit by investor', 'Profit by product'])
            ->and(collect($sheets['Profit by month'])->firstWhere(0, '2026-02')[1])->toEqual((float) $profit['months'][1]['profit'])
            ->and(collect($sheets['Forecast'])->firstWhere(0, $forecast['weeks'][0]['from'])[2])->toEqual((float) $forecast['weeks'][0]['amount']);
    });
});
