<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;

/*
| The contract and the promissory note as PDFs (Win Plan K5, CT23): the deal as signed, with its guarantors (ID numbers
| masked, and left out for a collector), the schedule and the customer's signature; the note for what the customer owes,
| the amount in figures and in words. Both carry the verification QR. The note stays behind its own switch until a
| lawyer approves its wording. Read back from the real PDF.
*/

beforeEach(fn () => $this->travelTo('2026-10-12 10:00:00'));

/** @return array{0: User, 1: Tenant, 2: Contract} a contract of 1,100.00 with a guarantor */
function paperContract(): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Guarantors);
    limitFreePlan(Feature::PdfStatements, null);
    $contract = openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Ahmad Salem'])->id, 'principal' => '1100.00']);
    test()->postJson("/api/v1/contracts/{$contract->id}/guarantors", ['name' => 'Khalid Al-Harbi', 'phone' => '0509876543', 'national_id' => '1098765432'])->assertCreated();

    return [$user, $tenant, $contract->fresh()];
}

function paperText(string $url): array
{
    $response = test()->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    return [normalised(pdfText((string) $response->getContent())), (string) $response->headers->get('X-Document-Code')];
}

describe('the contract', function () {
    it('prints the deal with its guarantor masked, the schedule and the signature, and verifies through its QR', function () {
        [, , $contract] = paperContract();
        test()->post("/api/v1/contracts/{$contract->id}/signature", ['file' => upload(drawnSignature(), 's.png')], ['Accept' => 'application/json'])->assertCreated();

        [$text, $code] = paperText("/api/v1/contracts/{$contract->id}/agreement.pdf?language=en");

        expect($text)->toContain(normalised('Instalment contract'))->toContain($contract->reference())
            ->toContain(normalised('Ahmad Salem'))->toContain(normalised('Khalid Al-Harbi'))->toContain(normalised('••••••432'))
            ->not->toContain('1098765432')->toContain(normalised('Signed in person'))
            ->and($code)->not->toBe('');
        $this->get('/verify/'.$code)->assertOk()->assertSee($contract->reference());
    });

    it('leaves the guarantors’ ID numbers out for a collector', function () {
        [, $tenant, $contract] = paperContract();

        apiMember('collector', $tenant);
        [$text] = paperText("/api/v1/contracts/{$contract->id}/agreement.pdf?language=en");

        expect($text)->toContain(normalised('Khalid Al-Harbi'))->not->toContain(normalised('••••••432'));
    });

    it('says not found for another workspace’s contract', function () {
        [, , $contract] = paperContract();

        apiOwner();
        $this->get("/api/v1/contracts/{$contract->id}/agreement.pdf")->assertNotFound();
    });
});

describe('the promissory note', function () {
    it('is for what is still owed once something is paid, never the whole price again', function () {
        [$user, , $contract] = paperContract();
        switchOn(Feature::PromissoryNote);
        app(RecordPayment::class)->handle($contract, '100.00', 'cash', by: $user);

        [$text] = paperText("/api/v1/contracts/{$contract->id}/promissory-note.pdf?language=en");

        expect($text)->toContain(normalised('one thousand Saudi Riyal'))->toContain(normalised('1,000.00'))
            ->not->toContain(normalised('one thousand one hundred'));
    });

    it('is not offered while its switch is off', function () {
        [, , $contract] = paperContract();

        $this->getJson("/api/v1/contracts/{$contract->id}/promissory-note.pdf")->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('promises what is owed, in figures and in words, payable on the last due date, and verifies', function () {
        [, , $contract] = paperContract();
        switchOn(Feature::PromissoryNote);

        [$text, $code] = paperText("/api/v1/contracts/{$contract->id}/promissory-note.pdf?language=en");

        expect($text)->toContain(normalised('Promissory note'))->toContain($contract->reference())
            ->toContain(normalised('Ahmad Salem'))->toContain(normalised('one thousand one hundred'))
            ->toContain(normalised('Due on 1 Apr 2026'))
            ->and($code)->not->toBe('');
        $this->get('/verify/'.$code)->assertOk();
    });

    it('is not for collectors', function () {
        [, $tenant, $contract] = paperContract();
        switchOn(Feature::PromissoryNote);

        apiMember('collector', $tenant);
        $this->get("/api/v1/contracts/{$contract->id}/promissory-note.pdf")->assertForbidden();
    });
});
