<?php

use App\Actions\Investors\CreateInvestor;
use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Investor;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Report filters (Win Plan RP11): the payments report and the customer statement narrowed to one investor's contracts
| or to the contracts that sold one product, for a period. The totals follow the filter, and the document says what it
| was narrowed to. Read back from the real PDF.
|
| Saeed funds C-0001, which sold a phone; the business funds C-0002. Both are Ahmad Salem's. C-0001 was paid 100.00 on
| 5 March and C-0002 37.00 on 6 March (so 137.00 in all, and a filtered total can never be read inside another).
*/

beforeEach(fn () => $this->travelTo('2026-04-10 12:00:00'));

/** @return array{saeed: Investor, phone: Product, customer: Customer, theirs: Contract, ours: Contract, tenant: Tenant, user: User} */
function filteredBooks(): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Investors);
    switchOn(Feature::ContractItems);
    limitFreePlan(Feature::Investors, null);
    limitFreePlan(Feature::PdfStatements, null);
    $saeed = app(CreateInvestor::class)->handle($tenant, ['name' => 'Saeed'], $user);
    $phone = asTenant($tenant, fn () => tap((new Product)->forceFill(['name' => 'Galaxy']))->save());
    $customer = customerIn($tenant, ['name' => 'Ahmad Salem']);
    $theirs = openContract($tenant, ['customer_id' => $customer->id, 'investor_id' => $saeed->id, 'items' => [['name' => 'Galaxy', 'product_id' => $phone->id, 'quantity' => 1, 'price' => '300.00']]]);
    $ours = openContract($tenant, ['customer_id' => $customer->id]);
    app(RecordPayment::class)->handle($theirs, '100.00', 'cash', by: $user, paidAt: Carbon::parse('2026-03-05 10:00:00'));
    app(RecordPayment::class)->handle($ours, '37.00', 'cash', by: $user, paidAt: Carbon::parse('2026-03-06 10:00:00'));

    return compact('saeed', 'phone', 'customer', 'theirs', 'ours', 'tenant', 'user');
}

function filteredPdf(string $url): string
{
    $response = test()->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    return normalised(pdfText((string) $response->getContent()));
}

describe('the payments report', function () {
    it('counts every payment of the period without a filter', function () {
        filteredBooks();

        $text = filteredPdf('/api/v1/reports/transactions.pdf?from=2026-03-01&to=2026-03-31&language=en');

        expect($text)->toContain('C-0001')->toContain('C-0002')->toContain(normalised('137.00'));
    });

    it('narrows the lines and the total to one investor’s contracts, and says so', function () {
        ['saeed' => $saeed] = filteredBooks();

        $text = filteredPdf("/api/v1/reports/transactions.pdf?from=2026-03-01&to=2026-03-31&investor={$saeed->id}&language=en");

        expect($text)->toContain('C-0001')->not->toContain('C-0002')->not->toContain(normalised('37.00'))
            ->toContain(normalised('Contracts funded by Saeed'));
    });

    it('narrows them to the contracts that sold one product, and says so', function () {
        ['phone' => $phone] = filteredBooks();

        $text = filteredPdf("/api/v1/reports/transactions.pdf?from=2026-03-01&to=2026-03-31&product={$phone->id}&language=en");

        expect($text)->toContain('C-0001')->not->toContain('C-0002')->toContain(normalised('Contracts that sold Galaxy'));
    });

    it('leaves out what was paid outside the period', function () {
        filteredBooks();

        $text = filteredPdf('/api/v1/reports/transactions.pdf?from=2026-03-06&to=2026-03-31&language=en');

        expect($text)->toContain('C-0002')->not->toContain('C-0001');
    });
});

describe('the customer statement', function () {
    it('narrows the contracts and the balance to one investor’s or one product’s, and says so', function () {
        ['saeed' => $saeed, 'phone' => $phone, 'customer' => $customer] = filteredBooks();

        $byInvestor = filteredPdf("/api/v1/customers/{$customer->id}/statement.pdf?investor={$saeed->id}&language=en");
        $byProduct = filteredPdf("/api/v1/customers/{$customer->id}/statement.pdf?product={$phone->id}&language=en");
        $all = filteredPdf("/api/v1/customers/{$customer->id}/statement.pdf?language=en");

        expect($byInvestor)->toContain('C-0001')->not->toContain('C-0002')->toContain(normalised('Contracts funded by Saeed'))
            ->and($byProduct)->toContain('C-0001')->not->toContain('C-0002')
            ->and($all)->toContain('C-0001')->toContain('C-0002');
    });
});

it('refuses an investor or a product of another workspace', function () {
    ['customer' => $customer] = filteredBooks();
    [, $other] = owner();
    $theirProduct = asTenant($other, fn () => tap((new Product)->forceFill(['name' => 'Theirs']))->save());

    $this->getJson('/api/v1/reports/transactions.pdf?product='.$theirProduct->id)->assertStatus(422);
    $this->getJson("/api/v1/customers/{$customer->id}/statement.pdf?product={$theirProduct->id}")->assertStatus(422);
});

it('offers the investor and the product in the web document menu, where there are any', function () {
    ['customer' => $customer] = filteredBooks();
    $owner = User::query()->firstOrFail();

    $this->actingAs($owner)->get('/app/payments')->assertOk()->assertSee('name="investor"', false)->assertSee('name="product"', false)->assertSee('Galaxy');
    $this->actingAs($owner)->get("/app/customers/{$customer->id}")->assertOk()->assertSee('name="investor"', false)->assertSee('name="product"', false);
});

it('offers only what can narrow something, and on a customer’s page only what is theirs', function () {
    ['tenant' => $tenant, 'user' => $user, 'customer' => $customer] = filteredBooks();
    app(CreateInvestor::class)->handle($tenant, ['name' => 'Idle Partner'], $user);
    asTenant($tenant, fn () => tap((new Product)->forceFill(['name' => 'Never Sold']))->save());
    $tablet = asTenant($tenant, fn () => tap((new Product)->forceFill(['name' => 'Tablet']))->save());
    $funder = app(CreateInvestor::class)->handle($tenant, ['name' => 'Other Funder'], $user);
    openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Someone Else'])->id, 'investor_id' => $funder->id, 'items' => [['name' => 'Tablet', 'product_id' => $tablet->id, 'quantity' => 1, 'price' => '200.00']]]);

    $this->actingAs($user)->get('/app/payments')->assertOk()
        ->assertSee('Other Funder')->assertSee('Tablet')->assertDontSee('Idle Partner')->assertDontSee('Never Sold');
    $this->actingAs($user)->get("/app/customers/{$customer->id}")->assertOk()
        ->assertSee('Saeed')->assertSee('Galaxy')->assertDontSee('Other Funder')->assertDontSee('Tablet');
});

it('never offers a collector the investors', function () {
    ['tenant' => $tenant, 'customer' => $customer] = filteredBooks();

    $this->actingAs(memberAs('collector', $tenant))->get("/app/customers/{$customer->id}")->assertOk()
        ->assertDontSee('name="investor"', false)->assertSee('name="product"', false);
});
