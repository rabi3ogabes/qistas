<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| The customer's signature (Win Plan K5, CT23, decision D6): drawn on the owner's screen, or on the customer's own
| phone through a link that works once, for a day, and only with the six-digit code sent to them apart. Nothing about
| the contract is shown before the code. The signature is kept with the contract, drawn again as a clean PNG.
*/

beforeEach(fn () => $this->travelTo('2026-10-12 10:00:00'));

/** @return array{0: User, 1: Tenant, 2: Contract} */
function signingContract(): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Guarantors);

    return [$user, $tenant, openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Ahmad Salem', 'phone' => '0501234567'])->id])];
}

function signOnScreen(Contract $contract, ?string $png = null): TestResponse
{
    return test()->post("/api/v1/contracts/{$contract->id}/signature", ['file' => upload($png ?? drawnSignature(), 'signature.png')], ['Accept' => 'application/json']);
}

/** @return array{url: string, code: string, token: string} */
function signingLink(Contract $contract): array
{
    $data = test()->postJson("/api/v1/contracts/{$contract->id}/signature-links")->assertCreated()->json('data');

    return ['url' => $data['url'], 'code' => $data['code'], 'token' => basename((string) parse_url($data['url'], PHP_URL_PATH))];
}

describe('on the owner’s screen', function () {
    it('keeps the signature drawn on the screen with when and how it was signed', function () {
        [$user, $tenant, $contract] = signingContract();

        signOnScreen($contract)->assertCreated()
            ->assertJsonPath('data.method', 'screen')->assertJsonPath('data.name', 'Ahmad Salem')
            ->assertJsonPath('data.signed_at', '2026-10-12T10:00:00Z');

        $signature = $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->json('data.signature');
        expect($signature['method'])->toBe('screen')->and($signature['image'])->toStartWith('data:image/png;base64,')
            ->and(asTenant($tenant, fn () => ContractSignature::query()->sole()->recorded_by_user_id))->toBe($user->id)
            ->and(AuditLog::query()->where('action', 'contract.signed')->count())->toBe(1);
    });

    it('draws the signature again, so nothing hidden in the picture is kept', function () {
        [, $tenant, $contract] = signingContract();
        $png = drawnSignature();
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $withText = substr($png, 0, -12).$chunk('tEXt', "Comment\0GPS:24.7136N").substr($png, -12);

        signOnScreen($contract, $withText)->assertCreated();

        $kept = base64_decode(asTenant($tenant, fn () => ContractSignature::query()->sole()->image));
        expect($withText)->toContain('GPS:')->and($kept)->toStartWith("\x89PNG")->not->toContain('GPS:');
    });

    it('refuses anything but a picture, and keeps a new signature in place of the old one', function () {
        [, , $contract] = signingContract();

        signOnScreen($contract, pdfBytes())->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
        // A photo is not a signature: it has no transparent background and would print as a box.
        signOnScreen($contract, jpegBytes(300, 100))->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
        signOnScreen($contract)->assertCreated();
        $this->travel(1)->hours();
        signOnScreen($contract)->assertCreated();

        expect($this->getJson("/api/v1/contracts/{$contract->id}")->json('data.signature.signed_at'))->toBe('2026-10-12T11:00:00Z')
            ->and(AuditLog::query()->where('action', 'contract.signed')->count())->toBe(2);
    });

    it('is for owners, managers and accountants, with the switch on', function () {
        [$user, $tenant, $contract] = signingContract();

        apiMember('collector', $tenant);
        signOnScreen($contract)->assertForbidden();

        switchOff(Feature::Guarantors);
        Sanctum::actingAs($user, ['app']);
        signOnScreen($contract)->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

describe('through a link', function () {
    it('makes a link for a day with a six-digit code, keeping neither in the clear', function () {
        [, , $contract] = signingContract();

        $data = $this->postJson("/api/v1/contracts/{$contract->id}/signature-links")->assertCreated()->json('data');

        expect($data['code'])->toMatch('/^\d{6}$/')->and($data['expires_at'])->toBe('2026-10-13T10:00:00Z')
            ->and($data['phone'])->toBe('0501234567')->and($data['url'])->toStartWith(url('/sign/'));
        $row = DB::table('signature_links')->sole();
        expect(json_encode($row))->not->toContain($data['code'])->not->toContain(basename($data['url']));
    });

    it('asks for the code before showing anything about the contract', function () {
        [, , $contract] = signingContract();
        ['url' => $url] = signingLink($contract);

        $this->get($url)->assertOk()->assertSee('name="code"', false)->assertSee('noindex', false)
            ->assertDontSee('Start free')->assertDontSee(route('register'), false)
            ->assertDontSee($contract->reference())->assertDontSee('Ahmad Salem');
    });

    it('shows the contract after the right code and keeps the signature drawn there', function () {
        [, $tenant, $contract] = signingContract();
        ['url' => $url, 'code' => $code] = signingLink($contract);

        $this->post($url.'/code', ['code' => $code])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee($contract->reference())->assertSee('Ahmad Salem')->assertSee('data-signature-pad', false);
        $this->post($url, ['drawing' => 'data:image/png;base64,'.base64_encode(drawnSignature())])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Thank you');

        $signature = asTenant($tenant, fn () => ContractSignature::query()->sole());
        expect($signature->method)->toBe('link')->and($signature->recorded_by_user_id)->toBeNull()->and($signature->ip)->not->toBeNull()
            ->and(DB::table('signature_links')->value('used_at'))->not->toBeNull();
        $this->get($url)->assertStatus(410);
        $this->post($url.'/code', ['code' => $code])->assertStatus(410);
    });

    it('takes Arabic digits in the code', function () {
        [, , $contract] = signingContract();
        ['url' => $url, 'code' => $code] = signingLink($contract);
        $arabic = strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        $this->post($url.'/code', ['code' => $arabic])->assertRedirect($url);
        $this->get($url)->assertSee('Ahmad Salem');
    });

    it('refuses a wrong code and closes the link after five', function () {
        [, , $contract] = signingContract();
        ['url' => $url, 'code' => $code] = signingLink($contract);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $try) {
            $this->post($url.'/code', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post($url.'/code', ['code' => $wrong]);

        $this->post($url.'/code', ['code' => $code]);
        $this->get($url)->assertStatus(410)->assertDontSee('Ahmad Salem');
    });

    it('cannot be signed without the code', function () {
        [, $tenant, $contract] = signingContract();
        ['url' => $url] = signingLink($contract);

        $this->post($url, ['drawing' => 'data:image/png;base64,'.base64_encode(drawnSignature())])->assertForbidden();

        expect(asTenant($tenant, fn () => ContractSignature::query()->count()))->toBe(0);
    });

    it('lasts a day, and a new link closes the one before', function () {
        [, , $contract] = signingContract();
        ['url' => $first] = signingLink($contract);
        ['url' => $second] = signingLink($contract);

        $this->get($first)->assertStatus(410);
        $this->get($second)->assertOk();

        $this->travel(25)->hours();
        $this->get($second)->assertStatus(410);
    });

    it('is not found for another workspace, and is not for collectors', function () {
        [, $tenant, $contract] = signingContract();

        apiMember('collector', $tenant);
        $this->postJson("/api/v1/contracts/{$contract->id}/signature-links")->assertForbidden();

        apiOwner();
        $this->postJson("/api/v1/contracts/{$contract->id}/signature-links")->assertNotFound();
        $this->get('/sign/'.str_repeat('a', 48))->assertStatus(410);
    });
});

describe('on the web', function () {
    it('takes a signature on the contract page, and shows it with when and how', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('data-signature-pad', false)->assertSee(route('app.contracts.signature-link.store', $contract), false);
        $this->actingAs($owner)->post(route('app.contracts.signature.store', $contract), ['drawing' => 'data:image/png;base64,'.base64_encode(drawnSignature())])
            ->assertRedirect(route('app.contracts.show', $contract));

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('data:image/png;base64,', false)->assertSee('Signed in person on');
    });

    it('shows the signing link and its code once, ready to send apart', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant, ['customer_id' => customerIn($tenant, ['phone' => '0501234567'])->id]);

        $page = $this->actingAs($owner)->followingRedirects()->post(route('app.contracts.signature-link.store', $contract))->assertOk()
            ->assertSee(url('/sign/'), false)->assertSee('https://wa.me/966501234567', false)->assertSee('sms:', false);
        expect($page->getContent())->toMatch('/data-code="\d{6}"/');

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertDontSee(url('/sign/'), false);
    });

    it('offers the contract PDF, and the promissory note only while its switch is on', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))
            ->assertSee(route('app.contracts.agreement', $contract), false)->assertDontSee(route('app.contracts.promissory-note', $contract), false);

        switchOn(Feature::PromissoryNote);
        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertSee(route('app.contracts.promissory-note', $contract), false);
    });
});
