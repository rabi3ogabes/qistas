<?php

use App\Actions\Account\PurgeDeletedAccounts;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

/*
 * Win Plan PP1 (SC04): Google Play and Apple require that someone can delete their account inside the app. An owner
 * deletes the business: it turns read-only for 30 days, can be restored in that time, then everything is erased.
 * Anyone else deletes only their own login, at once.
 */

/** An owner signed in to the API, with $count customers. */
function deletingOwner(int $count = 1): array
{
    [$user, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
    foreach (range(1, $count) as $i) {
        customerIn($tenant, ['name' => "Customer {$i}"]);
    }

    return [$user, $tenant];
}

function deletionBody(array $overrides = []): array
{
    return array_merge(['password' => TEST_PASSWORD, 'confirm_name' => 'Al-Fares Electronics'], $overrides);
}

describe('an owner deleting the business', function () {
    it('needs the password', function () {
        [, $tenant] = deletingOwner();

        $this->postJson('/api/v1/account/deletion', deletionBody(['password' => 'wrong-password']))
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['password']]]);

        expect($tenant->fresh()->deletion_requested_at)->toBeNull();
    });

    it('needs the business name typed exactly, so it is never done by accident', function () {
        [, $tenant] = deletingOwner();

        $this->postJson('/api/v1/account/deletion', deletionBody(['confirm_name' => 'Al Fares']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['confirm_name']]]);

        expect($tenant->fresh()->deletion_requested_at)->toBeNull();
    });

    it('needs the authenticator code when two-step sign-in is on', function () {
        $secret = app(Google2FA::class)->generateSecretKey();
        [$user, $tenant] = owner(['name' => 'Al-Fares Electronics']);
        $user->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();
        Sanctum::actingAs($user, ['app']);

        $this->postJson('/api/v1/account/deletion', deletionBody())->assertStatus(422)->assertJsonPath('error.code', 'two_factor_required');
        $this->postJson('/api/v1/account/deletion', deletionBody(['code' => '000000']))->assertStatus(401)->assertJsonPath('error.code', 'invalid_two_factor_code');
        expect($tenant->fresh()->deletion_requested_at)->toBeNull();

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/api/v1/account/deletion', deletionBody(['code' => $code]))->assertStatus(202);
        expect($tenant->fresh()->deletion_requested_at)->not->toBeNull();
    });

    it('turns the business read-only for 30 days and says until when it can be restored', function () {
        $this->travelTo('2026-10-11 10:00:00');
        [$user, $tenant] = deletingOwner();

        $this->postJson('/api/v1/account/deletion', deletionBody())
            ->assertStatus(202)
            ->assertJsonPath('data.scope', 'workspace')
            ->assertJsonPath('data.restore_until', '2026-11-10');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.deletion_scheduled_for', '2026-11-10');
        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', ['name' => 'New one', 'phone' => '+966501112233'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'workspace_deleting')
            ->assertJsonPath('error.restore_until', '2026-11-10');

        expect(AuditLog::query()->where('action', 'account.deletion_requested')->sole()->user_id)->toBe($user->id);
    });

    it('keeps other members read-only too, and lets only the owner restore', function () {
        [, $tenant] = deletingOwner();
        $this->postJson('/api/v1/account/deletion', deletionBody())->assertStatus(202);

        apiMember('manager', $tenant);
        $this->postJson('/api/v1/customers', ['name' => 'New one', 'phone' => '+966501112233'])->assertStatus(423);
        $this->deleteJson('/api/v1/account/deletion')->assertForbidden();

        expect($tenant->fresh()->deletion_requested_at)->not->toBeNull();
    });

    it('can be restored within the 30 days, and everything works again', function () {
        [, $tenant] = deletingOwner();
        $this->postJson('/api/v1/account/deletion', deletionBody())->assertStatus(202);

        $this->deleteJson('/api/v1/account/deletion')->assertOk()->assertJsonPath('data.restored', true);

        expect($tenant->fresh()->deletion_requested_at)->toBeNull();
        $this->postJson('/api/v1/customers', ['name' => 'New one', 'phone' => '+966501112233'])->assertCreated();
        expect(AuditLog::query()->where('action', 'account.deletion_cancelled')->count())->toBe(1);
    });
});

describe('the purge after 30 days', function () {
    it('erases the business, its records and files, and the people who belonged only to it', function () {
        Storage::fake('files');
        $this->travelTo('2026-10-11 10:00:00');
        [$owner, $tenant] = deletingOwner(3);
        $manager = memberAs('manager', $tenant);
        $alsoElsewhere = memberAs('viewer', $tenant);
        [, $other] = owner();
        $other->users()->attach($alsoElsewhere->id, ['role' => 'viewer']);
        openContract($tenant);
        Storage::disk('files')->put("tenants/{$tenant->id}/id_front/a.jpg", 'photo');
        Storage::disk('files')->put("tenants/{$other->id}/id_front/b.jpg", 'photo');
        $this->postJson('/api/v1/account/deletion', deletionBody())->assertStatus(202);

        $this->travelTo('2026-11-10 09:00:00');
        expect(app(PurgeDeletedAccounts::class)->handle())->toBe(0);

        $this->travelTo('2026-11-10 10:01:00');
        expect(app(PurgeDeletedAccounts::class)->handle())->toBe(1);

        expect(Tenant::query()->find($tenant->id))->toBeNull()
            ->and(DB::table('customers')->where('tenant_id', $tenant->id)->count())->toBe(0)
            ->and(DB::table('transactions')->where('tenant_id', $tenant->id)->count())->toBe(0)
            ->and(User::query()->find($owner->id))->toBeNull()
            ->and(User::query()->find($manager->id))->toBeNull()
            ->and(User::query()->find($alsoElsewhere->id))->not->toBeNull()
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $owner->id)->count())->toBe(0)
            ->and(Storage::disk('files')->exists("tenants/{$tenant->id}/id_front/a.jpg"))->toBeFalse()
            ->and(Storage::disk('files')->exists("tenants/{$other->id}/id_front/b.jpg"))->toBeTrue()
            ->and(Tenant::query()->find($other->id))->not->toBeNull()
            ->and(asTenant($other, fn () => Customer::count()))->toBe(0);

        // What stays is the bare fact that a deletion happened, with no personal detail.
        $audits = DB::table('audit_logs')->where('tenant_id', $tenant->id)->get();
        expect($audits->every(fn ($row) => $row->changes === null && $row->ip === null && $row->user_agent === null))->toBeTrue();
        expect(DB::table('account_deletions')->where('tenant_id', $tenant->id)->value('completed_at'))->not->toBeNull();
    });

    it('never touches a business whose deletion was cancelled', function () {
        $this->travelTo('2026-10-11 10:00:00');
        [, $tenant] = deletingOwner();
        $this->postJson('/api/v1/account/deletion', deletionBody())->assertStatus(202);
        $this->deleteJson('/api/v1/account/deletion')->assertOk();

        $this->travelTo('2026-12-01 10:00:00');

        expect(app(PurgeDeletedAccounts::class)->handle())->toBe(0)->and($tenant->fresh())->not->toBeNull();
    });

    it('runs from the scheduler every day', function () {
        $events = collect(app(Schedule::class)->events())->map->command;

        expect($events->filter(fn ($command) => str_contains((string) $command, 'qistas:purge-deleted-accounts'))->count())->toBe(1);
    });
});

describe('a member deleting their own login', function () {
    it('removes them from the business at once and leaves the business as it is', function () {
        [, $tenant] = owner();
        $collector = apiMember('collector', $tenant);

        $this->postJson('/api/v1/account/deletion', ['password' => TEST_PASSWORD])
            ->assertOk()->assertJsonPath('data.scope', 'login');

        expect(User::query()->find($collector->id))->toBeNull()
            ->and($tenant->fresh()->deletion_requested_at)->toBeNull()
            ->and($tenant->fresh()->users()->count())->toBe(1);
    });

    it('keeps the login of someone who still belongs to another business', function () {
        [, $tenant] = owner();
        [, $other] = owner();
        $viewer = memberAs('viewer', $tenant);
        $other->users()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->forceFill(['current_tenant_id' => $tenant->id])->save();
        Sanctum::actingAs($viewer, ['app']);

        $this->postJson('/api/v1/account/deletion', ['password' => TEST_PASSWORD])->assertOk();

        expect(User::query()->find($viewer->id))->not->toBeNull()
            ->and($viewer->fresh()->roleIn($tenant->id))->toBeNull()
            ->and($viewer->fresh()->roleIn($other->id))->not->toBeNull();
    });
});

describe('on the web', function () {
    it('explains how to delete an account to anyone, signed in or not', function () {
        $this->get('/account/delete')->assertOk()
            ->assertSee('Delete your Qistas account')
            ->assertSee('30 days');
    });

    it('lets the owner ask for deletion from the account page, then restore it', function () {
        [$user, $tenant] = owner(['name' => 'Al-Fares Electronics']);

        $this->actingAs($user)->get('/app/account/delete')->assertOk()->assertSee('name="confirm_name"', false);

        $this->actingAs($user)->post('/app/account/delete', deletionBody())->assertRedirect('/app');
        expect($tenant->fresh()->deletion_requested_at)->not->toBeNull();

        // Every page then says so, with the way back.
        $this->actingAs($user)->get('/app')->assertOk()->assertSee('This business will be deleted on')->assertSee('Restore');

        $this->actingAs($user)->delete('/app/account/delete')->assertRedirect('/app');
        expect($tenant->fresh()->deletion_requested_at)->toBeNull();
    });

    it('refuses changes on the web while the business is being deleted', function () {
        [$user, $tenant] = owner(['name' => 'Al-Fares Electronics']);
        $tenant->forceFill(['deletion_requested_at' => now(), 'delete_after' => now()->addDays(30)])->save();

        $this->actingAs($user)->from('/app/customers/create')
            ->post('/app/customers', ['name' => 'New one', 'phone' => '+966501112233'])
            ->assertRedirect('/app/customers/create')
            ->assertSessionHas('error');

        expect(asTenant($tenant, fn () => Customer::count()))->toBe(0);
    });
});
