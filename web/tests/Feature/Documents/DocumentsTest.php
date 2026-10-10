<?php

use App\Actions\Investors\CreateInvestor;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Documents\BusinessProfile;
use App\Documents\DocumentPreferences;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
| Win Plan PP8: statements, reports and receipts as PDF files a shop sends to a customer's WhatsApp or prints at the
| counter. These read the real PDF back, so "the Arabic is there" and "the cost is not" are checked, not assumed.
*/

beforeEach(function () {
    $this->travelTo('2026-04-10 12:00:00');
});

/**
 * An owner signed in to the API, and C-0001 for أحمد سالم: 3 x 100.00 from 1 Feb 2026, with cost 180.00 and 150.00 paid
 * on 5 Feb.
 *
 * @return array{0: User, 1: Tenant, 2: Customer, 3: Contract, 4: Transaction}
 */
function documentWorkspace(string $plan = 'free', array $tenant = []): array
{
    [$user, $workspace] = apiOwner($tenant);
    if ($plan !== 'free') {
        $workspace->subscribeTo(Plan::where('key', $plan)->sole());
    }
    $customer = customerIn($workspace, ['name' => 'أحمد سالم']);
    switchOn(Feature::ContractItems);
    $contract = openContract($workspace, ['customer_id' => $customer->id, 'cost_price' => '180.00']);
    $payment = app(RecordPayment::class)->handle($contract, '150.00', 'cash', by: $user, paidAt: Carbon::parse('2026-02-05 10:00:00'));

    return [$user, $workspace, $customer, $contract, $payment];
}

function pdfOf(TestResponse $response): string
{
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

    return (string) $response->getContent();
}

function documentsUsed(Tenant $tenant): int
{
    return (int) Entitlements::for($tenant)->check(Feature::PdfStatements)->used();
}

describe('each template', function () {
    it('writes Arabic that reads back from the PDF', function (string $kind) {
        [$user, $tenant, $customer, $contract, $payment] = documentWorkspace();
        partnersAllowed();
        $investor = app(CreateInvestor::class)->handle($tenant, ['name' => 'سعيد الشريك'], $user);
        unlimitedDocuments();

        $url = match ($kind) {
            'customer' => "/api/v1/customers/{$customer->id}/statement.pdf",
            'contract' => "/api/v1/contracts/{$contract->id}/statement.pdf",
            'receipt' => "/api/v1/payments/{$payment->id}/receipt.pdf",
            'transactions' => '/api/v1/reports/transactions.pdf?from=2026-02-01&to=2026-02-28',
            'investor' => "/api/v1/investors/{$investor->id}/report.pdf",
        };
        $expected = match ($kind) {
            'customer', 'contract' => ['كشف حساب', 'أحمد سالم'],
            'receipt' => ['إيصال', 'أحمد سالم'],
            'transactions' => ['تقرير الدفعات', 'أحمد سالم'],
            'investor' => ['تقرير المستثمر', 'سعيد الشريك'],
        };

        $text = pdfText(pdfOf($this->get($url.(str_contains($url, '?') ? '&' : '?').'language=ar')));

        foreach ($expected as $words) {
            expect(containsArabic($text, $words))->toBeTrue("[{$words}] is missing from the {$kind} document");
        }
    })->with(['customer', 'contract', 'receipt', 'transactions', 'investor']);
});

/** Investors are switched on and Free may have more than its own capital, for the tests that need a partner. */
function partnersAllowed(): void
{
    switchOn(Feature::Investors);
    limitFreePlan(Feature::Investors, null);
}

/** Tests that make several documents and are not about the allowance lift it. */
function unlimitedDocuments(): void
{
    limitFreePlan(Feature::PdfStatements, null);
}

describe('a statement', function () {
    it('writes amounts in Arabic without the invisible marks its fonts cannot draw', function () {
        [, , , $contract] = documentWorkspace();

        $text = pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=ar")));

        expect(preg_match('/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text))->toBe(0);
    });

    it('fits a usual contract on one page: six instalments, what was sold, two payments, the cost and the signature', function () {
        [$user, $tenant, $customer] = documentWorkspace();
        asTenant($tenant, fn () => BusinessProfile::for($tenant)->storeAsset('signature', pngBytes(), $user));
        $contract = openContract($tenant, ['customer_id' => $customer->id, 'principal' => '3600.00', 'installment_count' => 6, 'cost_price' => '2900.00', 'title' => 'iPhone 16 Pro, 256 GB',
            'items' => [['name' => 'iPhone 16 Pro', 'quantity' => 1, 'serial' => '490154203237518', 'price' => '3600.00', 'cost' => '2900.00']]]);
        app(RecordPayment::class)->handle($contract, '600.00', 'cash', by: $user, paidAt: Carbon::parse('2026-02-03 10:00:00'));
        app(RecordPayment::class)->handle($contract, '450.00', 'card', by: $user, paidAt: Carbon::parse('2026-03-05 10:00:00'));

        expect(pdfPages(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en&sections[cost]=1"))))->toBe(1);
    });

    it('shows the running balance and what is late', function () {
        [, , , $contract] = documentWorkspace();

        $text = pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en")));

        expect($text)->toContain('Running balance')->toContain('Overdue')->toContain('300.00')->toContain('150.00');
    });

    it('keeps the cost to the shop unless someone who sees the money asks for it', function () {
        [, $tenant, , $contract] = documentWorkspace();
        unlimitedDocuments();

        expect(pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en"))))->not->toContain('What it cost you')
            ->and(pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en&sections[cost]=1"))))->toContain('What it cost you')->toContain('180.00');

        apiMember('collector', $tenant);
        expect(pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en&sections[cost]=1"))))->not->toContain('What it cost you');
    });

    it('is a one-page summary when asked', function () {
        [, , $customer] = documentWorkspace();

        $text = pdfText(pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf?language=en&summary=1")));

        expect($text)->toContain('C-0001')->not->toContain('Running balance');
    });

    it('is A5 when asked', function () {
        [, , $customer] = documentWorkspace();

        expect(pdfPageSize(pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf?paper=a5"))))->toBe([148.0, 210.0]);
    });

    it('is another workspace’s customer: not found', function () {
        [, , $customer] = documentWorkspace();
        apiOwner();

        $this->get("/api/v1/customers/{$customer->id}/statement.pdf")->assertNotFound();
    });
});

describe('the allowance', function () {
    it('counts each different document once, and the same one again within ten minutes not at all', function () {
        [, $tenant, $customer, $contract] = documentWorkspace();
        limitFreePlan(Feature::PdfStatements, 2);

        pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf"));
        pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf"));
        expect(documentsUsed($tenant))->toBe(1);

        pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf"));
        expect(documentsUsed($tenant))->toBe(2);

        $this->get("/api/v1/customers/{$customer->id}/statement.pdf?paper=a5")->assertStatus(402)->assertJsonPath('error.code', 'limit_reached');
        // Still the same document as before: free to make again.
        pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf"));
        expect(documentsUsed($tenant))->toBe(2);
    });

    it('counts the same document again after ten minutes', function () {
        [, $tenant, $customer] = documentWorkspace();
        limitFreePlan(Feature::PdfStatements, 5);

        pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf"));
        $this->travel(11)->minutes();
        pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf"));

        expect(documentsUsed($tenant))->toBe(2);
    });
});

describe('branding', function () {
    it('shows the Qistas footer and no logo on Free', function () {
        [$user, $tenant, $customer] = documentWorkspace();
        asTenant($tenant, fn () => BusinessProfile::for($tenant)->storeAsset('logo', pngBytes(), $user));

        $pdf = pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf?language=en"));

        expect(pdfImages($pdf))->toBe(0)->and(pdfText($pdf))->toContain('Made with Qistas');
    });

    it('shows the shop’s logo and its own footer on Pro', function () {
        [$user, $tenant, $customer] = documentWorkspace('pro');
        asTenant($tenant, function () use ($tenant, $user): void {
            BusinessProfile::for($tenant)->storeAsset('logo', pngBytes(), $user);
            BusinessProfile::for($tenant)->save(['name_en' => 'Salem Mobiles', 'footer' => 'Thank you for shopping with us'], $user);
        });

        $pdf = pdfOf($this->get("/api/v1/customers/{$customer->id}/statement.pdf?language=en"));

        expect(pdfImages($pdf))->toBe(1)->and(pdfText($pdf))->toContain('Thank you for shopping with us')->not->toContain('Made with Qistas');
    });

    it('signs with the shop’s signature, unless the signature is turned off', function () {
        [$user, $tenant, , $contract] = documentWorkspace();
        unlimitedDocuments();
        asTenant($tenant, fn () => BusinessProfile::for($tenant)->storeAsset('signature', pngBytes(), $user));

        expect(pdfImages(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf"))))->toBe(1)
            ->and(pdfImages(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?sections[signature]=0"))))->toBe(0);
    });

    it('uses the shop’s own words', function () {
        [$user, $tenant] = documentWorkspace();
        unlimitedDocuments();
        DocumentPreferences::save($tenant, ['wording' => ['investor' => 'Partner']], $user);
        partnersAllowed();
        $investor = app(CreateInvestor::class)->handle($tenant, ['name' => 'Saeed'], $user);

        expect(pdfText(pdfOf($this->get("/api/v1/investors/{$investor->id}/report.pdf?language=en"))))->toContain('Partner')->not->toContain('Investor report');
    });
});

describe('verification', function () {
    it('shows only the shop, the reference, the date and the total', function () {
        [$user, $tenant, , $contract] = documentWorkspace();
        asTenant($tenant, fn () => BusinessProfile::for($tenant)->save(['name_en' => 'Salem Mobiles'], $user));
        pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf"));
        $code = asTenant($tenant, fn () => Document::sole()->verification_code);

        $this->app['auth']->forgetGuards();
        $this->get("/verify/{$code}")->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Salem Mobiles')->assertSee('C-0001')->assertSee('150.00')->assertSee('10 Apr 2026')
            ->assertDontSee('أحمد سالم');
    });

    it('puts the verification address on the document as a QR code and in words', function () {
        [, $tenant, , $contract] = documentWorkspace();

        $text = pdfText(pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf?language=en")));
        $code = asTenant($tenant, fn () => Document::sole()->verification_code);

        expect($text)->toContain($code);
    });

    it('does not know a code that was never issued, even when others were', function () {
        [, , , $contract] = documentWorkspace();
        pdfOf($this->get("/api/v1/contracts/{$contract->id}/statement.pdf"));

        $this->get('/verify/ABCDEFGHJK')->assertNotFound();
    });
});

describe('a receipt', function () {
    it('shows the date and time in the workspace’s own time zone, and what the payment covered', function () {
        [$user, $tenant, , $contract] = documentWorkspace(tenant: ['timezone' => 'Asia/Riyadh']);
        // Recorded now: 12:00 UTC is 15:00 in Riyadh.
        $payment = app(RecordPayment::class)->handle($contract, '60.00', 'cash', by: $user);

        $text = pdfText(pdfOf($this->get("/api/v1/payments/{$payment->id}/receipt.pdf?language=en")));

        expect($text)->toContain('10 Apr 2026')->toContain('15:00')->toContain('60.00')->toContain('C-0001-2')->toContain('90.00');
    });

    it('prints on an 80 mm till roll, as long as it needs to be', function () {
        [, , , , $payment] = documentWorkspace();

        [$width, $height] = pdfPageSize(pdfOf($this->get("/api/v1/payments/{$payment->id}/receipt.pdf?paper=80mm")));

        expect($width)->toBe(80.0)->and($height)->toBeLessThan(400.0);
    });

    it('is only for money that came in', function () {
        [$user, $tenant, , , $payment] = documentWorkspace();
        $void = app(VoidTransaction::class)->handle($payment, 'Twice', $user);

        $this->get("/api/v1/payments/{$void->id}/receipt.pdf")->assertNotFound();
    });
});

describe('the transactions report', function () {
    it('lists the period’s payments with their total', function () {
        [$user, , , $contract] = documentWorkspace();
        app(RecordPayment::class)->handle($contract, '40.00', 'card', by: $user, paidAt: Carbon::parse('2026-03-20 09:00:00'));

        $text = pdfText(pdfOf($this->get('/api/v1/reports/transactions.pdf?language=en&from=2026-03-01&to=2026-03-31')));

        expect($text)->toContain('40.00')->not->toContain('150.00');
    });

    it('is not for collectors', function () {
        [, $tenant] = documentWorkspace();
        apiMember('collector', $tenant);

        $this->get('/api/v1/reports/transactions.pdf')->assertForbidden();
    });
});
