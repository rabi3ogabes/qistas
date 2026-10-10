<?php

use App\Actions\RecordPayment;
use App\Documents\BusinessProfile;
use App\Documents\DocumentPreferences;
use App\Entitlements\Feature;
use App\Models\BusinessAsset;
use Illuminate\Http\UploadedFile;

/*
| Win Plan PP8: who the documents come from (the shop's names, phone, address, CR and VAT numbers, footer, logo and a
| signature drawn on the screen) and how they look by default (paper, text size, sections, the shop's own words).
| Anyone in the business may look; the owner and managers change them.
*/

beforeEach(function () {
    $this->travelTo('2026-04-10 12:00:00');
});

describe('the business profile in the API', function () {
    it('starts empty, saves what the owner types, and keeps what was not sent', function () {
        apiOwner();

        $this->getJson('/api/v1/settings/business-profile')->assertOk()
            ->assertJsonPath('data.name_en', null)->assertJsonPath('data.logo', null)->assertJsonPath('meta.can_edit', true);

        $this->putJson('/api/v1/settings/business-profile', ['name_ar' => 'جوالات سالم', 'name_en' => 'Salem Mobiles', 'vat_number' => '300012345600003'])->assertOk()
            ->assertJsonPath('data.name_ar', 'جوالات سالم')->assertJsonPath('data.vat_number', '300012345600003');
        $this->putJson('/api/v1/settings/business-profile', ['phone' => '+966 55 123 4567'])->assertOk()
            ->assertJsonPath('data.name_en', 'Salem Mobiles')->assertJsonPath('data.phone', '+966 55 123 4567');
    });

    it('is changed only by the owner and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        apiMember($role, $tenant);

        $this->putJson('/api/v1/settings/business-profile', ['name_en' => 'Mine'])->assertStatus($status);
        $this->postJson('/api/v1/settings/business-profile/logo', ['file' => UploadedFile::fake()->createWithContent('logo.png', pngBytes())])->assertStatus($status);
    })->with([['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('keeps a logo redrawn as a picture the documents can show', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/settings/business-profile/logo', ['file' => UploadedFile::fake()->createWithContent('logo.png', pngBytes())])->assertOk()
            ->assertJsonPath('data.logo', fn (string $uri) => str_starts_with($uri, 'data:image/png;base64,'));

        expect(asTenant($tenant, fn () => BusinessAsset::where('slot', 'logo')->sole()->sha256))->not->toBe(hash('sha256', pngBytes()));
    });

    it('takes only a PNG for a signature, and never something that is not a picture', function () {
        apiOwner();

        $this->postJson('/api/v1/settings/business-profile/signature', ['file' => UploadedFile::fake()->createWithContent('sign.jpg', jpegBytes())])
            ->assertStatus(422)->assertJsonPath('error.fields.file.0', 'A signature must be a PNG picture.');
        $this->postJson('/api/v1/settings/business-profile/logo', ['file' => UploadedFile::fake()->createWithContent('logo.png', pdfBytes())])->assertStatus(422);
        $this->postJson('/api/v1/settings/business-profile/signature', ['file' => UploadedFile::fake()->createWithContent('sign.png', pngBytes())])->assertOk()
            ->assertJsonPath('data.signature', fn (string $uri) => str_starts_with($uri, 'data:image/png;base64,'));
    });

    it('removes a logo', function () {
        [$user, $tenant] = apiOwner();
        BusinessProfile::for($tenant)->storeAsset('logo', pngBytes(), $user);

        $this->deleteJson('/api/v1/settings/business-profile/logo')->assertOk()->assertJsonPath('data.logo', null);
    });

    it('is another workspace’s: never seen', function () {
        [$user, $tenant] = owner();
        BusinessProfile::for($tenant)->save(['name_en' => 'Salem Mobiles'], $user);
        apiOwner();

        $this->getJson('/api/v1/settings/business-profile')->assertJsonPath('data.name_en', null);
    });
});

describe('the document preferences in the API', function () {
    it('start on A4, normal text, the cost off and the rest on', function () {
        apiOwner();

        $this->getJson('/api/v1/settings/documents')->assertOk()->assertExactJson(['data' => [
            'paper' => 'a4', 'text' => 'normal', 'sections' => ['cost' => false, 'overdue' => true, 'schedule' => true, 'signature' => true], 'wording' => [],
        ]]);
    });

    it('keep the owner’s choices and words, and drop a word sent empty', function () {
        [$user, $tenant] = apiOwner();

        $this->putJson('/api/v1/settings/documents', ['paper' => 'a5', 'sections' => ['schedule' => false], 'wording' => ['investor' => 'Partner']])->assertOk()
            ->assertJsonPath('data.paper', 'a5')->assertJsonPath('data.sections.schedule', false)->assertJsonPath('data.sections.overdue', true)
            ->assertJsonPath('data.wording.investor', 'Partner');
        $this->putJson('/api/v1/settings/documents', ['wording' => ['investor' => '']])->assertOk()->assertJsonPath('data.wording', []);

        expect(DocumentPreferences::for($tenant)->paper)->toBe('a5');
    });

    it('refuse a paper or a section that does not exist, and a word that is too long', function () {
        apiOwner();

        $this->putJson('/api/v1/settings/documents', ['paper' => 'letter'])->assertStatus(422);
        $this->putJson('/api/v1/settings/documents', ['sections' => ['secrets' => true]])->assertStatus(422);
        $this->putJson('/api/v1/settings/documents', ['wording' => ['investor' => str_repeat('x', 31)]])->assertStatus(422);
    });

    it('set what a document starts from', function () {
        [$user, $tenant] = apiOwner();
        $contract = openContract($tenant);
        limitFreePlan(Feature::PdfStatements, null);
        DocumentPreferences::save($tenant, ['paper' => 'a5'], $user);

        expect(pdfPageSize($this->get("/api/v1/contracts/{$contract->id}/statement.pdf")->getContent()))->toBe([148.0, 210.0]);
    });
});

describe('on the web', function () {
    it('keeps the business profile and the document preferences on one page', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->get(route('app.settings.business'))->assertOk()
            ->assertSee('name="name_ar"', false)->assertSee('name="vat_number"', false)->assertSee('name="paper"', false);

        $this->actingAs($user)->put(route('app.settings.business.update'), ['name_en' => 'Salem Mobiles', 'cr_number' => '1010101010'])
            ->assertRedirect(route('app.settings.business'));
        $this->actingAs($user)->put(route('app.settings.documents.update'), ['paper' => 'a5', 'text' => 'large', 'sections' => ['cost' => '0', 'overdue' => '1', 'schedule' => '0', 'signature' => '1'], 'wording' => ['investor' => 'Partner']])
            ->assertRedirect(route('app.settings.business'));

        expect(BusinessProfile::for($tenant)->fields['cr_number'])->toBe('1010101010')
            ->and(DocumentPreferences::for($tenant)->toArray())->toMatchArray(['paper' => 'a5', 'text' => 'large', 'wording' => ['investor' => 'Partner']])
            ->and(DocumentPreferences::for($tenant)->sections['schedule'])->toBeFalse();
    });

    it('takes a logo and a signature drawn on the screen', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->post(route('app.settings.business.assets.store', 'logo'), ['file' => UploadedFile::fake()->createWithContent('logo.jpg', jpegBytes(200, 80))])
            ->assertRedirect(route('app.settings.business'));
        $this->actingAs($user)->post(route('app.settings.business.assets.store', 'signature'), ['drawing' => 'data:image/png;base64,'.base64_encode(pngBytes())])
            ->assertRedirect(route('app.settings.business'));

        expect(BusinessProfile::for($tenant)->logo())->not->toBeNull()->and(BusinessProfile::for($tenant)->signature())->not->toBeNull();
        $this->actingAs($user)->get(route('app.settings.business'))->assertSee('data:image/png;base64,', false);
    });

    it('shows a collector the profile but never lets them change it', function () {
        [, $tenant] = owner();
        $collector = memberAs('collector', $tenant);

        $this->actingAs($collector)->get(route('app.settings.business'))->assertOk()->assertDontSee('name="name_ar"', false);
        $this->actingAs($collector)->put(route('app.settings.business.update'), ['name_en' => 'Mine'])->assertForbidden();
    });

    it('offers each document where it belongs, and opens it in the browser', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '50.00', 'cash', by: $user);
        limitFreePlan(Feature::PdfStatements, null);

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertSee(route('app.customers.statement', $contract->customer_id), false);
        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertSee(route('app.contracts.statement', $contract), false)
            ->assertSee(route('app.payments.receipt', $payment), false);
        $this->actingAs($user)->get(route('app.payments.index'))->assertSee(route('app.reports.transactions'), false);

        $this->actingAs($user)->get(route('app.contracts.statement', $contract))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'inline; filename="statement-c-0001.pdf"');
    });

    it('sends someone at their limit back with the upgrade sheet', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        limitFreePlan(Feature::PdfStatements, 0);

        $this->actingAs($user)->from(route('app.contracts.show', $contract))->get(route('app.contracts.statement', $contract))
            ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('upgrade');
    });
});
