<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Sandbox\DemoCleanup;
use App\Sandbox\TestWorkspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| The narrow clean-up behind `qistas:purge-demo` (go-live basics 6.4 and D1). It plans first and deletes only what the
| plan lists: accounts left by the old public demo (`demo_expires_at` set) and the developer seeder's own account, with
| the workspaces that belong to them alone. An address at the demo domain is not enough (a real person could type one),
| a workspace a real person also belongs to is never removed, and staff test workspaces go only when asked for.
*/

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00'); // 15:00 in Riyadh
    switchOn(Feature::DayClose);
    Storage::fake('files');
});

/**
 * A throw-away account as the old demo button made it: a random address at the demo domain, an expiry, and a workspace
 * of its own flagged as a demo.
 *
 * @return array{0: User, 1: Tenant}
 */
function cleanupDemoAccount(string $business = 'Demo Shop'): array
{
    $user = User::factory()->create([
        'email' => 'demo-'.Str::lower(Str::random(14)).'@demo.qistas.test',
        'demo_expires_at' => now()->addHours(12),
    ]);
    $tenant = cleanupOwnedWorkspace($user, $business, ['is_demo' => true]);

    return [$user, $tenant];
}

/**
 * A workspace in Riyadh that [$owner] signed up, as RegisterTenantOwner makes it (owner link and membership).
 *
 * @param  array<string, mixed>  $flags
 */
function cleanupOwnedWorkspace(User $owner, string $name, array $flags = []): Tenant
{
    $tenant = Tenant::factory()->create(['name' => $name, 'country' => 'SA', 'timezone' => 'Asia/Riyadh']);
    $tenant->forceFill(['owner_user_id' => $owner->id] + $flags)->save();
    $tenant->users()->attach($owner->id, ['role' => 'owner']);
    $tenant->subscribeTo(Plan::where('key', 'free')->sole());

    return $tenant;
}

/**
 * Everything a shop keeps: a customer with a contract of three instalments, a payment, a document, a stored file and a
 * closed day. That is 1 + 1 + 3 + 1 + 1 + 1 + 1 = 9 rows of business records.
 */
function cleanupFill(Tenant $tenant, User $by): void
{
    $contract = openContract($tenant);
    closeTake($contract, '100.00', by: $by);
    closeDay($tenant, '2026-10-21', $by, '100.00');

    DB::table('documents')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'receipt', 'params_hash' => str_repeat('a', 64),
        'verification_code' => Str::upper(Str::random(10)), 'reference' => 'R-1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $path = "tenants/{$tenant->id}/contract_photo/".Str::uuid().'.jpg';
    Storage::disk('files')->put($path, 'photo');
    DB::table('stored_files')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'contract_photo', 'path' => $path, 'mime' => 'image/jpeg',
        'size' => 5, 'sha256' => hash('sha256', 'photo'), 'subject_type' => 'contract', 'subject_id' => $contract->id,
        'uploaded_by_user_id' => $by->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A signed-in browser and a phone for [$user]. */
function cleanupSignIn(User $user): void
{
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $user->createToken('phone', ['app']);
}

/** @return list<string> */
function cleanupPlannedUsers(array $plan): array
{
    return array_column($plan['accounts'], 'user_id');
}

/** @return list<string> */
function cleanupHeldBackUsers(array $plan): array
{
    return array_column($plan['held_back'], 'user_id');
}

/**
 * A request to delete a workspace, as RequestAccountDeletion leaves it (scope `workspace`), or to delete a login.
 *
 * @param  array<string, mixed>  $overrides
 */
function cleanupDeletion(string $tenantId, string $scope = 'workspace', array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('account_deletions')->insert($overrides + [
        'id' => $id, 'tenant_id' => $tenantId, 'user_id' => null, 'scope' => $scope, 'requested_at' => now()->subDays(3),
        'delete_after' => now()->addDays(27), 'cancelled_at' => null, 'completed_at' => null,
        'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
    ]);

    return $id;
}

function cleanupTenantRows(string $tenantId): int
{
    return collect(['customers', 'contracts', 'installments', 'transactions', 'documents', 'day_closes', 'stored_files'])
        ->sum(fn (string $table) => DB::table($table)->where('tenant_id', $tenantId)->count());
}

describe('the plan', function () {
    it('lists a demo account with its workspace and how many records go with it', function () {
        [$user, $tenant] = cleanupDemoAccount('Demo Shop');
        cleanupFill($tenant, $user);

        $plan = app(DemoCleanup::class)->plan();

        expect($plan['accounts'])->toBe([[
            'user_id' => $user->id,
            'email' => $user->email,
            'matched_by' => 'demo_expires_at',
            'created_at' => '2026-10-21T12:00:00Z',
            'workspaces' => [['id' => $tenant->id, 'name' => 'Demo Shop', 'rows' => 9]],
        ]])->and($plan['test_workspaces'])->toBe([])
            ->and($plan['held_back'])->toBe([]);
    });

    it('says how each account matched and when it was made, so a real person at the seeder’s address stands out', function () {
        [$old] = cleanupDemoAccount();

        $this->travelTo('2026-09-30 08:30:15');
        $seeded = User::factory()->create(['email' => DemoSeeder::EMAIL]);
        $this->travelTo('2026-10-21 12:00:00');

        $byUser = collect(app(DemoCleanup::class)->plan()['accounts'])->keyBy('user_id');

        expect($byUser)->toHaveCount(2)
            ->and($byUser[$old->id]['matched_by'])->toBe('demo_expires_at')
            ->and($byUser[$old->id]['created_at'])->toBe('2026-10-21T12:00:00Z')
            ->and($byUser[$seeded->id]['matched_by'])->toBe('seeder_address')
            ->and($byUser[$seeded->id]['created_at'])->toBe('2026-09-30T08:30:15Z');
    });

    it('calls an account with both signs a demo-button account', function () {
        User::factory()->create(['email' => DemoSeeder::EMAIL, 'demo_expires_at' => now()->addHours(12)]);

        expect(collect(app(DemoCleanup::class)->plan()['accounts'])->sole()['matched_by'])->toBe('demo_expires_at');
    });

    it('lists the developer seeder’s own account and its sample business', function () {
        $this->seed(DemoSeeder::class);
        $seeded = User::where('email', DemoSeeder::EMAIL)->sole();
        $workspace = Tenant::query()->where('owner_user_id', $seeded->id)->sole();

        $account = collect(app(DemoCleanup::class)->plan()['accounts'])->sole();

        expect($account['user_id'])->toBe($seeded->id)
            ->and($account['email'])->toBe(DemoSeeder::EMAIL)
            ->and($account['workspaces'])->toHaveCount(1)
            ->and($account['workspaces'][0]['id'])->toBe($workspace->id)
            ->and($account['workspaces'][0]['rows'])->toBe(cleanupTenantRows($workspace->id))
            ->and($account['workspaces'][0]['rows'])->toBeGreaterThan(0);
    });

    it('never lists a real account at the demo domain (D1), nor any other real account', function () {
        $lookalike = User::factory()->create(['email' => 'layla@demo.qistas.test']);
        cleanupOwnedWorkspace($lookalike, 'Layla Phones', ['is_demo' => true]);
        [$real] = owner();
        cleanupDemoAccount();

        $planned = cleanupPlannedUsers(app(DemoCleanup::class)->plan());

        expect($planned)->toHaveCount(1)->not->toContain($lookalike->id)->not->toContain($real->id);
    });

    it('never lists platform staff, even at the seeder’s address', function () {
        $this->seed(DemoSeeder::class);
        User::where('email', DemoSeeder::EMAIL)->update(['platform_role' => 'super_admin']);

        expect(app(DemoCleanup::class)->plan()['accounts'])->toBe([]);
    });

    it('lists staff test workspaces under their own heading', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin', 'email' => 'staff@qistas.example']);
        $test = app(TestWorkspace::class)->open($admin);

        $plan = app(DemoCleanup::class)->plan();

        expect($plan['accounts'])->toBe([])
            ->and($plan['test_workspaces'])->toBe([['id' => $test->id, 'name' => $test->name, 'owner_email' => 'staff@qistas.example']]);
    });

    it('holds back a demo account whose workspace a real person also belongs to', function () {
        [$demo, $shared] = cleanupDemoAccount();
        memberAs('manager', $shared);

        expect(cleanupPlannedUsers(app(DemoCleanup::class)->plan()))->not->toContain($demo->id);
    });

    it('lists what it holds back, with the reason in plain words', function () {
        [$demo, $shared] = cleanupDemoAccount('Shared Shop');
        memberAs('manager', $shared);

        $held = app(DemoCleanup::class)->plan()['held_back'];

        expect($held)->toHaveCount(1)
            ->and(array_keys($held[0]))->toBe(['user_id', 'email', 'reason'])
            ->and($held[0]['user_id'])->toBe($demo->id)
            ->and($held[0]['email'])->toBe($demo->email)
            ->and($held[0]['reason'])->toContain('"Shared Shop"')->toContain('not a demo account');
    });

    it('holds back an account that owns a staff test workspace, and says what that needs', function () {
        $demo = User::factory()->create(['demo_expires_at' => now()->addHours(12)]);
        cleanupOwnedWorkspace($demo, 'Staff Sandbox', ['is_test' => true]);
        $cleanup = app(DemoCleanup::class);

        $without = $cleanup->plan()['held_back'];
        $with = $cleanup->plan(includeTest: true)['held_back'];

        expect($without)->toHaveCount(1)->and($without[0]['user_id'])->toBe($demo->id)
            ->and($without[0]['reason'])->toContain('"Staff Sandbox"')->toContain('staff test workspace')->toContain('--include-test')
            ->and($with)->toHaveCount(1)->and($with[0]['user_id'])->toBe($demo->id)
            ->and($with[0]['reason'])->toContain('"Staff Sandbox"')->toContain('run the purge again');
    });

    it('gives one reason per held-back account, and holds it back whole', function () {
        $demo = User::factory()->create(['demo_expires_at' => now()->addHours(12)]);
        $first = cleanupOwnedWorkspace($demo, 'A Shared Shop');
        memberAs('collector', $first);
        cleanupOwnedWorkspace($demo, 'B Staff Sandbox', ['is_test' => true]);

        $plan = app(DemoCleanup::class)->plan();

        expect($plan['held_back'])->toHaveCount(1)->and($plan['accounts'])->toBe([]);
    });

    it('lists nothing as held back when everything can go', function () {
        cleanupDemoAccount();
        $this->seed(DemoSeeder::class);

        expect(app(DemoCleanup::class)->plan()['held_back'])->toBe([]);
    });

    it('never lists as held back someone who is not a demo account', function () {
        $lookalike = User::factory()->create(['email' => 'layla@demo.qistas.test']);
        $shop = cleanupOwnedWorkspace($lookalike, 'Layla Phones', ['is_demo' => true]);
        memberAs('manager', $shop);
        [$real, $realShop] = owner();
        $realShop->forceFill(['owner_user_id' => $real->id])->save();
        memberAs('manager', $realShop);

        expect(app(DemoCleanup::class)->plan()['held_back'])->toBe([]);
    });
});

describe('the purge', function () {
    it('removes a demo account completely: its workspace and every record, its file, its sessions and its tokens', function () {
        [$user, $tenant] = cleanupDemoAccount();
        cleanupFill($tenant, $user);
        cleanupSignIn($user);
        [$real, $realShop] = owner();
        cleanupSignIn($real);
        $cleanup = app(DemoCleanup::class);

        expect($cleanup->purge($cleanup->plan()))->toBe(1);

        expect(User::find($user->id))->toBeNull()
            ->and(Tenant::find($tenant->id))->toBeNull()
            ->and(cleanupTenantRows($tenant->id))->toBe(0)
            ->and(DB::table('tenant_users')->where('tenant_id', $tenant->id)->count())->toBe(0)
            ->and(Storage::disk('files')->allFiles("tenants/{$tenant->id}"))->toBe([])
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
            // The real business next door is untouched, signed in as before.
            ->and(User::find($real->id))->not->toBeNull()
            ->and(Tenant::find($realShop->id))->not->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $real->id)->count())->toBe(1)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $real->id)->count())->toBe(1)
            // What was removed is on record, as counts only.
            ->and(AuditLog::where('action', 'demo.purged')->sole()->changes)->toBe(['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0]);
    });

    it('removes the developer seeder’s account and its sample business', function () {
        $this->seed(DemoSeeder::class);
        $seeded = User::where('email', DemoSeeder::EMAIL)->sole();
        $workspaceId = Tenant::query()->where('owner_user_id', $seeded->id)->sole()->id;
        $cleanup = app(DemoCleanup::class);

        expect($cleanup->purge($cleanup->plan()))->toBe(1);

        expect(User::find($seeded->id))->toBeNull()
            ->and(Tenant::find($workspaceId))->toBeNull()
            ->and(cleanupTenantRows($workspaceId))->toBe(0);
    });

    it('leaves a real account at the demo domain alone', function () {
        $lookalike = User::factory()->create(['email' => 'layla@demo.qistas.test']);
        $shop = cleanupOwnedWorkspace($lookalike, 'Layla Phones', ['is_demo' => true]);
        cleanupFill($shop, $lookalike);
        $cleanup = app(DemoCleanup::class);

        expect($cleanup->purge($cleanup->plan()))->toBe(0);

        expect(User::find($lookalike->id))->not->toBeNull()
            ->and(Tenant::find($shop->id))->not->toBeNull()
            ->and(cleanupTenantRows($shop->id))->toBe(9);
    });

    it('deletes only what the plan lists, however the data changed after it', function () {
        [$listed] = cleanupDemoAccount('First');
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        [$later, $laterShop] = cleanupDemoAccount('Made after the plan');

        expect($cleanup->purge($plan))->toBe(1);

        expect(User::find($listed->id))->toBeNull()
            ->and(User::find($later->id))->not->toBeNull()
            ->and(Tenant::find($laterShop->id))->not->toBeNull();
    });

    it('never deletes an account that is not a demo account, even when a plan names it (only ever a demo account)', function () {
        [$real, $shop] = owner();
        $shop->forceFill(['owner_user_id' => $real->id])->save();
        $forged = ['accounts' => [['user_id' => $real->id, 'email' => $real->email, 'workspaces' => [['id' => $shop->id, 'name' => $shop->name, 'rows' => 0]]]], 'test_workspaces' => []];

        expect(app(DemoCleanup::class)->purge($forged))->toBe(0);

        expect(User::find($real->id))->not->toBeNull()->and(Tenant::find($shop->id))->not->toBeNull();
    });

    it('never deletes a real person who owns nothing, even when a plan names them', function () {
        $member = memberAs('collector', workspaceOn());
        $lookalike = User::factory()->create(['email' => 'sara@demo.qistas.test']);
        $forged = ['accounts' => [
            ['user_id' => $member->id, 'email' => $member->email, 'workspaces' => []],
            ['user_id' => $lookalike->id, 'email' => $lookalike->email, 'workspaces' => []],
        ], 'test_workspaces' => []];

        expect(app(DemoCleanup::class)->purge($forged))->toBe(0);

        expect(User::find($member->id))->not->toBeNull()->and(User::find($lookalike->id))->not->toBeNull();
    });

    it('never takes a staff test workspace away with an account that owns it', function () {
        $demo = User::factory()->create(['demo_expires_at' => now()->addHours(12)]);
        $test = cleanupOwnedWorkspace($demo, 'Owned test workspace', ['is_test' => true]);
        $cleanup = app(DemoCleanup::class);

        $plan = $cleanup->plan();
        expect(cleanupPlannedUsers($plan))->not->toContain($demo->id)
            ->and(array_column($plan['test_workspaces'], 'id'))->toBe([$test->id]);

        expect($cleanup->purge($plan))->toBe(0);
        expect(Tenant::find($test->id))->not->toBeNull()->and(User::find($demo->id))->not->toBeNull();
    });

    it('never removes a workspace a real person belongs to, and keeps its owner in place', function () {
        [$demo, $shared] = cleanupDemoAccount();
        $member = memberAs('manager', $shared);
        $cleanup = app(DemoCleanup::class);

        expect($cleanup->purge($cleanup->plan()))->toBe(0);

        expect(Tenant::find($shared->id))->not->toBeNull()
            ->and(Tenant::find($shared->id)->owner_user_id)->toBe($demo->id)
            ->and(User::find($demo->id))->not->toBeNull()
            ->and(User::find($member->id))->not->toBeNull();
    });

    it('holds an account back when a real person joined its workspace after the plan', function () {
        [$demo, $shared] = cleanupDemoAccount();
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        expect(cleanupPlannedUsers($plan))->toContain($demo->id);

        $member = memberAs('collector', $shared);

        expect($cleanup->purge($plan))->toBe(0);
        expect(User::find($demo->id))->not->toBeNull()
            ->and(Tenant::find($shared->id)?->owner_user_id)->toBe($demo->id)
            ->and(DB::table('tenant_users')->where(['tenant_id' => $shared->id, 'user_id' => $member->id])->exists())->toBeTrue();
    });

    it('holds an account back when it made another workspace after the plan', function () {
        [$demo, $listed] = cleanupDemoAccount('Listed');
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();

        $unlisted = cleanupOwnedWorkspace($demo, 'Not in the plan', ['is_demo' => true]);

        expect($cleanup->purge($plan))->toBe(0);
        expect(User::find($demo->id))->not->toBeNull()
            ->and(Tenant::find($listed->id))->not->toBeNull()
            ->and(Tenant::find($unlisted->id)?->owner_user_id)->toBe($demo->id);
    });

    it('removes staff test workspaces only when asked to, and keeps the staff member', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $test = app(TestWorkspace::class)->open($admin);
        $cleanup = app(DemoCleanup::class);

        $cleanup->purge($cleanup->plan());
        expect(Tenant::find($test->id))->not->toBeNull();

        $cleanup->purge($cleanup->plan(includeTest: true));
        expect(Tenant::find($test->id))->toBeNull()
            ->and(cleanupTenantRows($test->id))->toBe(0)
            ->and(User::find($admin->id))->not->toBeNull()
            ->and(User::find($admin->id)->current_tenant_id)->toBeNull();
    });

    it('ignores the held-back list: nothing named there is touched', function () {
        [$demo, $shared] = cleanupDemoAccount();
        memberAs('manager', $shared);
        $real = User::factory()->create();
        $realShop = cleanupOwnedWorkspace($real, 'Real Shop');
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        expect(cleanupHeldBackUsers($plan))->toBe([$demo->id]);
        $plan['held_back'][] = ['user_id' => $real->id, 'email' => $real->email, 'reason' => 'forged'];

        expect($cleanup->purge($plan))->toBe(0);

        expect(User::find($demo->id))->not->toBeNull()->and(Tenant::find($shared->id))->not->toBeNull()
            ->and(User::find($real->id))->not->toBeNull()->and(Tenant::find($realShop->id))->not->toBeNull()
            ->and(AuditLog::where('action', 'demo.purged')->count())->toBe(0);
    });

    it('removes only the workspaces the account owns, however many ids a forged plan lists under it', function () {
        [$demo, $mine] = cleanupDemoAccount('Mine');
        $real = User::factory()->create();
        $theirs = cleanupOwnedWorkspace($real, 'Theirs');
        cleanupFill($theirs, $real);
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        $plan['accounts'][0]['workspaces'][] = ['id' => $theirs->id, 'name' => 'Theirs', 'rows' => 0];

        expect($cleanup->purge($plan))->toBe(1);

        expect(User::find($demo->id))->toBeNull()
            ->and(Tenant::find($mine->id))->toBeNull()
            ->and(Tenant::find($theirs->id))->not->toBeNull()
            ->and(Tenant::find($theirs->id)->owner_user_id)->toBe($real->id)
            ->and(cleanupTenantRows($theirs->id))->toBe(9)
            ->and(User::find($real->id))->not->toBeNull();
    });

    it('leaves an account alone when a forged plan lists a real workspace instead of the ones it owns', function () {
        [$demo, $mine] = cleanupDemoAccount('Mine');
        $real = User::factory()->create();
        $theirs = cleanupOwnedWorkspace($real, 'Theirs');
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        $plan['accounts'][0]['workspaces'] = [['id' => $theirs->id, 'name' => 'Theirs', 'rows' => 0]];

        expect($cleanup->purge($plan))->toBe(0);

        expect(User::find($demo->id))->not->toBeNull()
            ->and(Tenant::find($mine->id))->not->toBeNull()
            ->and(Tenant::find($theirs->id))->not->toBeNull();
    });

    it('refuses a forged test-workspace entry for a workspace that is not a staff test workspace, even with include_test', function () {
        $real = User::factory()->create();
        $shop = cleanupOwnedWorkspace($real, 'Real Shop');
        cleanupFill($shop, $real);
        $forged = [
            'accounts' => [], 'held_back' => [], 'include_test' => true,
            'test_workspaces' => [['id' => $shop->id, 'name' => 'Real Shop', 'owner_email' => $real->email]],
        ];

        expect(app(DemoCleanup::class)->purge($forged))->toBe(0);

        expect(Tenant::find($shop->id))->not->toBeNull()
            ->and(Tenant::find($shop->id)->owner_user_id)->toBe($real->id)
            ->and(cleanupTenantRows($shop->id))->toBe(9)
            ->and(User::find($real->id))->not->toBeNull()
            ->and(AuditLog::where('action', 'demo.purged')->count())->toBe(0);
    });

    it('takes away the login and memberships of a demo account that only belongs to a real workspace, and keeps that workspace’s records', function () {
        $real = User::factory()->create();
        $shop = cleanupOwnedWorkspace($real, 'Real Shop');
        $demo = User::factory()->create(['email' => 'demo-'.Str::lower(Str::random(14)).'@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);
        $shop->users()->attach($demo->id, ['role' => 'collector']);
        cleanupFill($shop, $demo); // the demo account took the payment, closed the day and added the file
        cleanupSignIn($demo);
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        expect(cleanupPlannedUsers($plan))->toBe([$demo->id])->and($plan['accounts'][0]['workspaces'])->toBe([]);

        expect($cleanup->purge($plan))->toBe(1);

        expect(User::find($demo->id))->toBeNull()
            ->and(DB::table('tenant_users')->where('user_id', $demo->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $demo->id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $demo->id)->count())->toBe(0)
            // The business next door: still there, still its owner's, every record in place (they only lose the author).
            ->and(Tenant::find($shop->id))->not->toBeNull()
            ->and(Tenant::find($shop->id)->owner_user_id)->toBe($real->id)
            ->and(DB::table('tenant_users')->where(['tenant_id' => $shop->id, 'user_id' => $real->id])->exists())->toBeTrue()
            ->and(cleanupTenantRows($shop->id))->toBe(9)
            ->and(Storage::disk('files')->allFiles("tenants/{$shop->id}"))->toHaveCount(1)
            ->and(DB::table('transactions')->where('tenant_id', $shop->id)->whereNull('created_by_user_id')->count())->toBe(1);
    });

    it('marks a pending deletion request of a removed workspace as completed, as the nightly erase does', function () {
        [$demo, $shop] = cleanupDemoAccount();
        $elsewhere = User::factory()->create();
        $elsewhereShop = cleanupOwnedWorkspace($elsewhere, 'Elsewhere');
        $pending = cleanupDeletion($shop->id);
        $cancelled = cleanupDeletion($shop->id, overrides: ['cancelled_at' => now()->subDay()]);
        $finished = cleanupDeletion($shop->id, overrides: ['completed_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $login = cleanupDeletion($shop->id, 'login');
        $other = cleanupDeletion($elsewhereShop->id);
        $cleanup = app(DemoCleanup::class);

        expect($cleanup->purge($cleanup->plan()))->toBe(1);

        $row = fn (string $id) => DB::table('account_deletions')->where('id', $id)->sole();
        expect($row($pending)->completed_at)->toBe('2026-10-21 12:00:00')
            ->and($row($pending)->updated_at)->toBe('2026-10-21 12:00:00')
            ->and($row($cancelled)->completed_at)->toBeNull()
            ->and($row($finished)->completed_at)->toBe('2026-10-19 12:00:00')
            ->and($row($login)->completed_at)->toBeNull()
            ->and($row($other)->completed_at)->toBeNull();
    });

    it('marks a pending deletion request of a removed staff test workspace as completed too', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $test = app(TestWorkspace::class)->open($admin);
        $pending = cleanupDeletion($test->id);
        $cleanup = app(DemoCleanup::class);

        $cleanup->purge($cleanup->plan(includeTest: true));

        expect(Tenant::find($test->id))->toBeNull()
            ->and(DB::table('account_deletions')->where('id', $pending)->sole()->completed_at)->toBe('2026-10-21 12:00:00');
    });

    it('reports who and what went, and only what really went', function () {
        [$gone, $goneShop] = cleanupDemoAccount('Goes');
        [$stays, $staysShop] = cleanupDemoAccount('Stays');
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        app(TestWorkspace::class)->open($admin);
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan(includeTest: true);
        memberAs('collector', $staysShop); // a real person joins after the list was made

        $report = $cleanup->purgeAndReport($plan);

        expect($report)->toBe(['accounts' => [$gone->email], 'workspaces' => 1, 'test_workspaces' => 1])
            ->and(User::find($stays->id))->not->toBeNull();
    });

    it('records each removal on its own, as counts only', function () {
        cleanupDemoAccount('First');
        cleanupDemoAccount('Second');
        $cleanup = app(DemoCleanup::class);

        $cleanup->purge($cleanup->plan());

        expect(AuditLog::where('action', 'demo.purged')->get()->pluck('changes')->all())
            ->toBe([['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0], ['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0]]);
    });

    it('records the removal of a staff test workspace on its own', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        app(TestWorkspace::class)->open($admin);
        $cleanup = app(DemoCleanup::class);

        $cleanup->purge($cleanup->plan(includeTest: true));

        expect(AuditLog::where('action', 'demo.purged')->sole()->changes)->toBe(['accounts' => 0, 'workspaces' => 0, 'test_workspaces' => 1]);
    });

    it('keeps the record of an account already removed when the next one fails half way', function () {
        $first = User::factory()->create(['email' => 'demo-a@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);
        $firstShop = cleanupOwnedWorkspace($first, 'First Shop', ['is_demo' => true]);
        $second = User::factory()->create(['email' => 'demo-b@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);
        $secondShop = cleanupOwnedWorkspace($second, 'Second Shop', ['is_demo' => true]);
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        expect(cleanupPlannedUsers($plan))->toBe([$first->id, $second->id]);

        // Any fault inside the second account's removal would do: here its lookup throws, as the removal starts.
        $armed = true;
        User::retrieved(function (User $user) use ($second, &$armed): void {
            if ($armed && $user->id === $second->id) {
                throw new RuntimeException('forced');
            }
        });

        expect(fn () => $cleanup->purgeAndReport($plan))->toThrow(RuntimeException::class, 'forced');
        $armed = false;

        expect(User::find($first->id))->toBeNull()->and(Tenant::find($firstShop->id))->toBeNull()
            ->and(User::find($second->id))->not->toBeNull()->and(Tenant::find($secondShop->id))->not->toBeNull()
            // The removal that committed is on record; the one that did not is not.
            ->and(AuditLog::where('action', 'demo.purged')->sole()->changes)->toBe(['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0]);
    });

    it('locks the workspaces an account owns before it looks at who belongs to them', function () {
        cleanupDemoAccount();
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $cleanup->purge($plan);

        $lock = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for update'));
        $members = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "tenant_users"'));
        expect($lock)->not->toBeFalse()->and($members)->not->toBeFalse()->and($lock)->toBeLessThan($members);
    })->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'row locks exist only on PostgreSQL');

    it('is harmless the second time', function () {
        cleanupDemoAccount();
        $cleanup = app(DemoCleanup::class);
        $plan = $cleanup->plan();

        expect($cleanup->purge($plan))->toBe(1)
            ->and($cleanup->purge($plan))->toBe(0)
            ->and($cleanup->plan()['accounts'])->toBe([])
            // Nothing removed the second time, so nothing recorded either.
            ->and(AuditLog::where('action', 'demo.purged')->count())->toBe(1);
    });
});
