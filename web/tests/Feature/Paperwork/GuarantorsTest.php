<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Guarantor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Guarantors (Win Plan K5, CT21): up to three on a contract, each with a name, a phone, how they know the customer and
| an ID number that is encrypted in the database and only ever shown masked. Collectors see a guarantor's phone, never
| the ID number. Owners, managers and accountants add and change them; owners and managers remove them.
*/

/** @return array{0: User, 1: Tenant, 2: Contract} an owner signed in to the API, with the switch on and one contract */
function guarantorContract(): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Guarantors);

    return [$user, $tenant, openContract($tenant)];
}

/** @param  array<string, mixed>  $overrides */
function guarantorInput(array $overrides = []): array
{
    return [...['name' => 'Khalid Al-Harbi', 'phone' => '0501234567', 'relation' => 'Brother', 'national_id' => '1098765432'], ...$overrides];
}

describe('adding them', function () {
    it('adds up to three, keeps the ID number encrypted and shows it masked', function () {
        [, $tenant, $contract] = guarantorContract();

        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertCreated()
            ->assertJsonPath('data.name', 'Khalid Al-Harbi')->assertJsonPath('data.phone', '0501234567')
            ->assertJsonPath('data.relation', 'Brother')->assertJsonPath('data.national_id', '••••••432');
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['name' => 'Saad', 'national_id' => null]))->assertCreated()
            ->assertJsonPath('data.national_id', null);
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['name' => 'Fahad']))->assertCreated();

        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['name' => 'Fourth']))
            ->assertUnprocessable()->assertJsonValidationErrors('guarantors', 'error.fields');

        expect(DB::table('guarantors')->pluck('national_id')->filter()->implode(' '))->not->toContain('1098765432')
            ->and(asTenant($tenant, fn () => Guarantor::query()->where('name', 'Khalid Al-Harbi')->sole()->national_id))->toBe('1098765432');
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonCount(3, 'data.guarantors')
            ->assertJsonPath('data.guarantors.0.name', 'Khalid Al-Harbi')->assertJsonPath('data.guarantors.2.name', 'Fahad');
    });

    it('takes Arabic digits and asks for a name and a real phone', function () {
        [, , $contract] = guarantorContract();

        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['phone' => '٠٥٠١٢٣٤٥٦٧', 'national_id' => '١٠٩٨٧٦٥٤٣٢']))->assertCreated()
            ->assertJsonPath('data.phone', '0501234567')->assertJsonPath('data.national_id', '••••••432');
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['name' => ' ', 'phone' => 'call me']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone'], 'error.fields');
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput(['relation' => str_repeat('x', 61)]))
            ->assertUnprocessable()->assertJsonValidationErrors('relation', 'error.fields');
    });

    it('is written to the activity log without the ID number', function () {
        [, , $contract] = guarantorContract();

        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertCreated();

        $row = AuditLog::query()->where('action', 'guarantor.added')->sole();
        expect(json_encode($row->toArray()))->not->toContain('1098765432')->not->toContain('432');
    });
});

describe('changing and removing them', function () {
    it('changes a guarantor and keeps the ID number unless a new one is typed or it is removed', function () {
        [, $tenant, $contract] = guarantorContract();
        $id = $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->json('data.id');
        $stored = fn () => asTenant($tenant, fn () => Guarantor::query()->findOrFail($id));

        $this->putJson("/api/v1/guarantors/{$id}", guarantorInput(['name' => 'Khalid S. Al-Harbi', 'national_id' => null]))->assertOk()
            ->assertJsonPath('data.name', 'Khalid S. Al-Harbi')->assertJsonPath('data.national_id', '••••••432');
        $this->putJson("/api/v1/guarantors/{$id}", guarantorInput(['national_id' => '2233445566']))->assertOk()->assertJsonPath('data.national_id', '••••••566');
        expect($stored()->national_id)->toBe('2233445566');

        $this->putJson("/api/v1/guarantors/{$id}", guarantorInput(['national_id' => null, 'remove_national_id' => true]))->assertOk()->assertJsonPath('data.national_id', null);
        expect($stored()->national_id)->toBeNull();
    });

    it('lets owners and managers remove one, and nobody else', function () {
        [, $tenant, $contract] = guarantorContract();
        $id = $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->json('data.id');

        apiMember('accountant', $tenant);
        $this->deleteJson("/api/v1/guarantors/{$id}")->assertForbidden();

        apiMember('manager', $tenant);
        $this->deleteJson("/api/v1/guarantors/{$id}")->assertNoContent();

        expect(asTenant($tenant, fn () => Guarantor::query()->count()))->toBe(0)
            ->and(AuditLog::query()->where('action', 'guarantor.removed')->count())->toBe(1);
    });
});

describe('who sees what', function () {
    it('shows a collector the guarantor’s phone but never the ID number, and lets them change nothing', function () {
        [, $tenant, $contract] = guarantorContract();
        $id = $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->json('data.id');

        $collector = apiMember('collector', $tenant);
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()
            ->assertJsonPath('data.guarantors.0.phone', '0501234567')->assertJsonPath('data.guarantors.0.national_id', null);
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertForbidden();
        $this->putJson("/api/v1/guarantors/{$id}", guarantorInput())->assertForbidden();

        $this->actingAs($collector)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('Khalid Al-Harbi')->assertSee('0501234567')->assertDontSee('••••••432');
    });

    it('keeps the ID number from a viewer too, and lets an accountant add one', function () {
        [, $tenant, $contract] = guarantorContract();

        apiMember('accountant', $tenant);
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertCreated()->assertJsonPath('data.national_id', '••••••432');

        apiMember('viewer', $tenant);
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.guarantors.0.national_id', null);
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertForbidden();
    });

    it('says not found for another workspace’s contract or guarantor', function () {
        [, , $contract] = guarantorContract();
        $id = $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->json('data.id');

        apiOwner();
        switchOn(Feature::Guarantors);
        $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertNotFound();
        $this->putJson("/api/v1/guarantors/{$id}", guarantorInput())->assertNotFound();
        $this->deleteJson("/api/v1/guarantors/{$id}")->assertNotFound();
    });
});

it('needs the switch on to add or change one, and keeps showing the ones already there when it is off', function () {
    [$user, $tenant, $contract] = guarantorContract();
    $id = $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->json('data.id');

    switchOff(Feature::Guarantors);
    Sanctum::actingAs($user, ['app']);

    $this->postJson("/api/v1/contracts/{$contract->id}/guarantors", guarantorInput())->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    $this->putJson("/api/v1/guarantors/{$id}", guarantorInput())->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonCount(1, 'data.guarantors');
});

describe('on the web', function () {
    it('adds, shows, changes and removes guarantors on the contract page', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);

        $this->actingAs($owner)->post(route('app.contracts.guarantors.store', $contract), guarantorInput())
            ->assertRedirect(route('app.contracts.show', $contract));
        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('Khalid Al-Harbi')->assertSee('0501234567')->assertSee('Brother')->assertSee('••••••432')
            ->assertSee(route('app.contracts.guarantors.store', $contract), false);

        $guarantor = asTenant($tenant, fn () => Guarantor::query()->sole());
        $this->actingAs($owner)->put(route('app.guarantors.update', $guarantor), guarantorInput(['relation' => 'Cousin', 'national_id' => '']))->assertRedirect();
        expect(asTenant($tenant, fn () => $guarantor->fresh()))->relation->toBe('Cousin')->national_id->toBe('1098765432');

        $this->actingAs($owner)->delete(route('app.guarantors.destroy', $guarantor))->assertRedirect();
        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertDontSee('Khalid Al-Harbi');
    });

    it('offers no form while the switch is off, and still shows the guarantors already there', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Guarantors);
        $contract = openContract($tenant);
        $this->actingAs($owner)->post(route('app.contracts.guarantors.store', $contract), guarantorInput())->assertRedirect();
        $guarantor = asTenant($tenant, fn () => Guarantor::query()->sole());

        switchOff(Feature::Guarantors);

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('Khalid Al-Harbi')
            ->assertDontSee(route('app.contracts.guarantors.store', $contract), false)
            ->assertDontSee('guarantor-'.$guarantor->id.'-name', false)
            ->assertDontSee(route('app.contracts.photos.store', $contract), false)
            ->assertSee(route('app.guarantors.destroy', $guarantor), false);
    });

    it('shows no card at all on a contract without paperwork while the switch is off', function () {
        [$owner, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()->assertDontSee('paperwork-title', false);
    });
});
