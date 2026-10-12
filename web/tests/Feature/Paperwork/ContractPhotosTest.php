<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Files;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Contract photos (Win Plan K5, CT22): the customer's ID (front and back), the signed paper contract and the invoice,
| kept with the contract. Each picture is drawn again without its location data, kept in the database on a host with
| no lasting disk (D5), and only ever handed out through a link that lasts five minutes. ID photos are not shown in
| the list: opening one is a deliberate act, kept to owners, managers and accountants, and written to the audit log.
*/

beforeEach(fn () => useDatabaseFiles());

/** @return array{0: User, 1: Tenant, 2: Contract} */
function photoContract(): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Guarantors);

    return [$user, $tenant, openContract($tenant)];
}

function sendPhoto(Contract $contract, string $kind, ?string $bytes = null): TestResponse
{
    return test()->post("/api/v1/contracts/{$contract->id}/photos", ['kind' => $kind, 'file' => upload($bytes ?? jpegBytes(), 'photo.jpg')], ['Accept' => 'application/json']);
}

describe('keeping them', function () {
    it('keeps a photo of the invoice with its location data removed, attached to the contract', function () {
        [$user, $tenant, $contract] = photoContract();

        sendPhoto($contract, 'invoice', jpegBytes(withExif: true))->assertCreated()
            ->assertJsonPath('data.kind', 'invoice')->assertJsonPath('data.mime', 'image/jpeg');

        $file = asTenant($tenant, fn () => StoredFile::query()->sole());
        expect((string) Storage::disk(Files::DISK)->get($file->path))->not->toContain('GPS:')->not->toContain('Exif')
            ->and($file->subject_type)->toBe((new Contract)->getMorphClass())->and($file->subject_id)->toBe($contract->id)
            ->and($file->uploaded_by_user_id)->toBe($user->id)
            ->and(DB::table('file_blobs')->where('path', $file->path)->exists())->toBeTrue();
    });

    it('takes the ID front and back, the signed contract and the invoice, and nothing else', function () {
        [, , $contract] = photoContract();

        foreach (['id_front', 'id_back', 'contract_photo', 'invoice'] as $kind) {
            sendPhoto($contract, $kind)->assertCreated();
        }

        sendPhoto($contract, 'payment_proof')->assertUnprocessable()->assertJsonValidationErrors('kind', 'error.fields');
        sendPhoto($contract, '../../etc')->assertUnprocessable()->assertJsonValidationErrors('kind', 'error.fields');
        sendPhoto($contract, 'invoice', pdfBytes())->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
    });

    it('keeps at most twelve photos on a contract', function () {
        [, , $contract] = photoContract();

        foreach (range(1, 12) as $n) {
            sendPhoto($contract, 'invoice')->assertCreated();
        }

        sendPhoto($contract, 'invoice')->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
    });
});

describe('handing them out', function () {
    it('lists the photos with five-minute links, but not the ID photos, which are opened one at a time and audited', function () {
        $this->travelTo('2026-10-12 12:00:00');
        [, , $contract] = photoContract();
        sendPhoto($contract, 'invoice')->assertCreated();
        $idFront = sendPhoto($contract, 'id_front')->json('data.id');

        $photos = collect($this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->json('data.photos'))->keyBy('kind');
        expect($photos)->toHaveCount(2)->and($photos['id_front']['url'])->toBeNull()->and($photos['id_front']['private'])->toBeTrue();
        parse_str((string) parse_url($photos['invoice']['url'], PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBeLessThanOrEqual(now()->addSeconds(300)->getTimestamp())
            ->and(AuditLog::query()->where('action', 'file.viewed')->count())->toBe(0);
        $this->get($photos['invoice']['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $url = $this->getJson("/api/v1/files/{$idFront}")->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        expect(AuditLog::query()->where('action', 'file.viewed')->count())->toBe(1);
    });

    it('keeps ID photos from collectors and viewers, who still see the contract and invoice photos', function () {
        [, $tenant, $contract] = photoContract();
        sendPhoto($contract, 'contract_photo')->assertCreated();
        $idBack = sendPhoto($contract, 'id_back')->json('data.id');

        foreach (['collector', 'viewer'] as $role) {
            apiMember($role, $tenant);
            $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()
                ->assertJsonCount(1, 'data.photos')->assertJsonPath('data.photos.0.kind', 'contract_photo');
            $this->getJson("/api/v1/files/{$idBack}")->assertForbidden();
        }
    });

    it('says not found for another workspace’s photo', function () {
        [, , $contract] = photoContract();
        $id = sendPhoto($contract, 'invoice')->json('data.id');

        apiOwner();
        $this->getJson("/api/v1/files/{$id}")->assertNotFound();
        $this->deleteJson("/api/v1/contracts/{$contract->id}/photos/{$id}")->assertNotFound();
        $this->getJson('/api/v1/files/'.Str::uuid())->assertNotFound();
    });
});

describe('who may add and remove them', function () {
    it('lets owners, managers and accountants add photos, and keeps collectors and viewers from it', function () {
        [, $tenant, $contract] = photoContract();

        foreach (['manager' => 201, 'accountant' => 201, 'collector' => 403, 'viewer' => 403] as $role => $status) {
            apiMember($role, $tenant);
            sendPhoto($contract, 'invoice')->assertStatus($status);
        }
    });

    it('lets owners and managers remove a photo, which takes its bytes away', function () {
        [, $tenant, $contract] = photoContract();
        $id = sendPhoto($contract, 'invoice')->json('data.id');
        $path = asTenant($tenant, fn () => StoredFile::query()->findOrFail($id)->path);

        apiMember('accountant', $tenant);
        $this->deleteJson("/api/v1/contracts/{$contract->id}/photos/{$id}")->assertForbidden();

        apiMember('manager', $tenant);
        $this->deleteJson("/api/v1/contracts/{$contract->id}/photos/{$id}")->assertNoContent();

        expect(DB::table('file_blobs')->where('path', $path)->exists())->toBeFalse()
            ->and($this->getJson("/api/v1/contracts/{$contract->id}")->json('data.photos'))->toBe([]);
    });

    it('only removes a photo through its own contract', function () {
        [, $tenant, $contract] = photoContract();
        $id = sendPhoto($contract, 'invoice')->json('data.id');
        $other = openContract($tenant);

        $this->deleteJson("/api/v1/contracts/{$other->id}/photos/{$id}")->assertNotFound();
    });

    it('needs the switch on to add one, and keeps listing those already there', function () {
        [$user, , $contract] = photoContract();
        sendPhoto($contract, 'invoice')->assertCreated();

        switchOff(Feature::Guarantors);

        sendPhoto($contract, 'invoice')->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonCount(1, 'data.photos');
    });
});

describe('on the web', function () {
    it('uploads a photo, shows it through a short link and an ID photo as a private tile, and removes it', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);

        $this->actingAs($owner)->post(route('app.contracts.photos.store', $contract), ['kind' => 'invoice', 'file' => upload(jpegBytes(), 'invoice.jpg')])
            ->assertRedirect(route('app.contracts.show', $contract));
        $this->actingAs($owner)->post(route('app.contracts.photos.store', $contract), ['kind' => 'id_front', 'file' => upload(jpegBytes(), 'id.jpg')])->assertRedirect();
        [$invoice, $id] = asTenant($tenant, fn () => [StoredFile::query()->where('kind', 'invoice')->sole(), StoredFile::query()->where('kind', 'id_front')->sole()]);

        $page = $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee(route('app.files.show', $id), false)->assertSee('ID, front')->assertSee('Invoice');
        expect($page->getContent())->toContain('signature=')
            ->and(AuditLog::query()->where('action', 'file.viewed')->count())->toBe(0);

        $this->actingAs($owner)->delete(route('app.contracts.photos.destroy', [$contract, $invoice]))->assertRedirect();
        expect(asTenant($tenant, fn () => StoredFile::query()->count()))->toBe(1);
    });

    it('shows a collector no ID photo at all', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);
        $this->actingAs($owner)->post(route('app.contracts.photos.store', $contract), ['kind' => 'id_front', 'file' => upload(jpegBytes(), 'id.jpg')])->assertRedirect();
        $id = asTenant($tenant, fn () => StoredFile::query()->sole());

        $this->actingAs(memberAs('collector', $tenant))->get(route('app.contracts.show', $contract))->assertOk()
            ->assertDontSee(route('app.files.show', $id), false)->assertDontSee('ID, front');
    });
});
