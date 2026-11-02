<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Sandbox\TestWorkspace;
use App\Support\GoLiveCheck;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| `qistas:purge-demo` (go-live basics 6.4): what the old public demo and the developer seeder left behind, listed first and
| removed only on `--confirm`. It is a wrapper around App\Sandbox\DemoCleanup, which holds every rule (and its own tests):
| these tests are about what the owner reads and what the two modes do with it.
*/

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00');
    switchOn(Feature::DayClose);
    Storage::fake('files');
});

/** A throw-away account as the old demo button made it, with a workspace of its own. */
function purgeDemoAccount(string $business = 'Demo Shop'): array
{
    $user = User::factory()->create(['email' => 'demo-'.Str::lower(Str::random(12)).'@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);

    return [$user, purgeDemoWorkspace($user, $business, ['is_demo' => true])];
}

/** @param  array<string, mixed>  $flags */
function purgeDemoWorkspace(User $owner, string $name, array $flags = []): Tenant
{
    $tenant = Tenant::factory()->create(['name' => $name, 'country' => 'SA', 'timezone' => 'Asia/Riyadh']);
    $tenant->forceFill(['owner_user_id' => $owner->id] + $flags)->save();
    $tenant->users()->attach($owner->id, ['role' => 'owner']);
    $tenant->subscribeTo(Plan::where('key', 'free')->sole());

    return $tenant;
}

/** One customer with a contract of three instalments and a payment: 1 + 1 + 3 + 1 = 6 rows of business records. */
function purgeDemoFill(Tenant $tenant, User $by): void
{
    closeTake(openContract($tenant), '100.00', by: $by);
}

/** Runs the command and gives back what it printed. */
function purgeDemoRun(array $options = []): string
{
    Artisan::call('qistas:purge-demo', $options);

    return Artisan::output();
}

/**
 * A real person joins [$shop] at the moment the removal looks [$demo] up again: after the command has made its list and
 * before the account is taken away (the list reads the account once, the removal reads it a second time).
 */
function purgeDemoRealPersonJoinsWhenRemovalLooksAt(User $demo, Tenant $shop): void
{
    $lookups = 0;

    User::retrieved(function (User $user) use ($demo, $shop, &$lookups): void {
        if ($user->id === $demo->id && ++$lookups === 2) {
            memberAs('collector', $shop);
        }
    });
}

/** Every table's row count, to show that a run changed nothing. */
function purgeDemoRowCounts(): array
{
    return collect(Schema::getTables(Schema::getCurrentSchemaName()))->pluck('name')->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

describe('without --confirm', function () {
    it('lists every account, how it matched, when it was made, its workspaces and records, and deletes nothing', function () {
        [$demo, $shop] = purgeDemoAccount('Demo Shop');
        purgeDemoFill($shop, $demo);
        $this->seed(DemoSeeder::class);
        $lookalike = User::factory()->create(['email' => 'layla@demo.qistas.test']);
        purgeDemoWorkspace($lookalike, 'Layla Phones', ['is_demo' => true]);
        [$held, $heldShop] = purgeDemoAccount('Shared Shop');
        memberAs('manager', $heldShop);
        $admin = User::factory()->create(['platform_role' => 'super_admin', 'email' => 'staff@qistas.example']);
        app(TestWorkspace::class)->open($admin);
        $before = purgeDemoRowCounts();

        $exit = Artisan::call('qistas:purge-demo');
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain($demo->email)->toContain('demo_expires_at')->toContain('2026-10-21T12:00:00Z')
            ->toContain('Demo Shop')->toMatch('/Demo Shop\W+6 records/')
            ->toContain(DemoSeeder::EMAIL)->toContain('seeder')
            // Held back, with the reason.
            ->toContain($held->email)->toContain('Shared Shop')->toContain('not a demo account')
            // The staff test workspace under its own heading, and what it needs.
            ->toContain('staff@qistas.example')->toContain('only removed with --include-test')
            // A real person at the demo domain is never listed.
            ->not->toContain('layla@demo.qistas.test')->not->toContain('Layla Phones')
            ->and(purgeDemoRowCounts())->toBe($before);
    });

    it('ends by saying nothing was deleted and how to run it for real', function () {
        purgeDemoAccount();

        $output = trim(purgeDemoRun());

        expect($output)->toMatch('/nothing was deleted/i')
            ->and(Str::afterLast($output, "\n"))->toContain('php artisan qistas:purge-demo --confirm')
            ->and(stripos($output, 'nothing was deleted'))->toBeGreaterThan(stripos($output, 'demo-'));
    });

    it('lists the staff test workspaces as removed, and how, when asked with --include-test', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin', 'email' => 'staff@qistas.example']);
        $test = app(TestWorkspace::class)->open($admin);
        purgeDemoAccount();

        $without = purgeDemoRun();
        $with = purgeDemoRun(['--include-test' => true]);

        expect($without)->toContain($test->name)->toContain('only removed with --include-test')
            ->not->toContain('--confirm --include-test')
            ->and($with)->toContain($test->name)->not->toContain('only removed with --include-test')
            ->toContain('php artisan qistas:purge-demo --confirm --include-test')
            ->and(Tenant::find($test->id))->not->toBeNull();
    });

    it('says plainly when there is nothing to remove', function () {
        [$real] = owner();

        $output = purgeDemoRun();

        expect($output)->toContain('Nothing to remove')->toMatch('/nothing was deleted/i')
            ->and(User::find($real->id))->not->toBeNull();
    });

    it('offers nothing to confirm when only held-back accounts and staff test workspaces are left', function () {
        [$held, $heldShop] = purgeDemoAccount('Shared Shop');
        memberAs('manager', $heldShop);

        $output = purgeDemoRun();

        expect($output)->toContain($held->email)->toContain('Nothing to remove')->not->toContain('--confirm');
    });

    it('says plainly that a demo account which only belongs to someone else’s business loses its login and keeps that business', function () {
        $real = User::factory()->create();
        $shop = purgeDemoWorkspace($real, 'Real Shop');
        $demo = User::factory()->create(['email' => 'demo-member@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);
        $shop->users()->attach($demo->id, ['role' => 'collector']);

        $output = purgeDemoRun();

        expect($output)->toContain('demo-member@demo.qistas.test')
            ->toContain('its login and membership will be removed; the business and its records are kept')
            ->not->toContain('none of its own')
            ->and(Tenant::find($shop->id))->not->toBeNull();
    });

    it('prints a business name as plain text, never as console formatting', function () {
        [$demo, $shop] = purgeDemoAccount("<fg=red>Evil</> \e[2J Shop");

        $output = purgeDemoRun();

        expect($output)->toContain('<fg=red>Evil</>')->not->toContain("\e")->toContain($demo->email);
    });
});

describe('with --confirm', function () {
    it('removes exactly the listed demo accounts and leaves everyone else', function () {
        [$first, $firstShop] = purgeDemoAccount('First Demo');
        purgeDemoFill($firstShop, $first);
        [$second, $secondShop] = purgeDemoAccount('Second Demo');
        $this->seed(DemoSeeder::class);
        $seeded = User::where('email', DemoSeeder::EMAIL)->sole();
        $lookalike = User::factory()->create(['email' => 'layla@demo.qistas.test']);
        $lookalikeShop = purgeDemoWorkspace($lookalike, 'Layla Phones', ['is_demo' => true]);
        purgeDemoFill($lookalikeShop, $lookalike);
        [$real, $realShop] = owner();
        [$held, $heldShop] = purgeDemoAccount('Shared Shop');
        $member = memberAs('manager', $heldShop);

        $output = purgeDemoRun(['--confirm' => true]);

        expect(User::find($first->id))->toBeNull()->and(Tenant::find($firstShop->id))->toBeNull()
            ->and(User::find($second->id))->toBeNull()->and(Tenant::find($secondShop->id))->toBeNull()
            ->and(User::find($seeded->id))->toBeNull()
            ->and(User::find($lookalike->id))->not->toBeNull()->and(Tenant::find($lookalikeShop->id))->not->toBeNull()
            ->and(User::find($real->id))->not->toBeNull()->and(Tenant::find($realShop->id))->not->toBeNull()
            ->and(User::find($held->id))->not->toBeNull()->and(Tenant::find($heldShop->id))->not->toBeNull()
            ->and(User::find($member->id))->not->toBeNull()
            ->and($output)->toContain('Removed 3 demo accounts and 3 workspaces')
            ->toContain($first->email)->toContain($second->email)->toContain(DemoSeeder::EMAIL)
            ->not->toContain('layla@demo.qistas.test')
            // What was left alone, and why.
            ->toContain($held->email)->toContain('held back');
    });

    it('says it removed one account and one workspace in the singular', function () {
        purgeDemoAccount();

        expect(purgeDemoRun(['--confirm' => true]))->toContain('Removed 1 demo account and 1 workspace.');
    });

    it('records each removal as counts only', function () {
        purgeDemoAccount();
        purgeDemoAccount();

        purgeDemoRun(['--confirm' => true]);

        $rows = AuditLog::where('action', 'demo.purged')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('changes')->all())->toBe([['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0], ['accounts' => 1, 'workspaces' => 1, 'test_workspaces' => 0]]);
    });

    it('names an account the list had but the check before removing left alone, and says so when that is all of them', function () {
        [$demo, $shop] = purgeDemoAccount('Joined Just Now');
        purgeDemoRealPersonJoinsWhenRemovalLooksAt($demo, $shop);

        $output = purgeDemoRun(['--confirm' => true]);

        expect($output)->toContain('Nothing was removed: every listed account changed since the list was made.')
            ->not->toContain('Nothing to remove')->not->toContain('Removed')
            ->and($output)->toContain($demo->email)->toContain('left alone: it changed since the list was made')
            ->and(User::find($demo->id))->not->toBeNull()->and(Tenant::find($shop->id))->not->toBeNull()
            ->and(AuditLog::where('action', 'demo.purged')->count())->toBe(0);
    });

    it('removes the others and names only the account it left alone', function () {
        [$gone, $goneShop] = purgeDemoAccount('Goes');
        [$kept, $keptShop] = purgeDemoAccount('Joined Just Now');
        purgeDemoRealPersonJoinsWhenRemovalLooksAt($kept, $keptShop);

        $output = purgeDemoRun(['--confirm' => true]);

        expect($output)->toContain('Removed 1 demo account and 1 workspace.')->not->toContain('Nothing was removed')
            ->and($output)->toMatch('/'.preg_quote($kept->email, '/').'\W+left alone: it changed since the list was made/')
            ->and(User::find($gone->id))->toBeNull()->and(User::find($kept->id))->not->toBeNull();
    });

    it('works the list out again at that moment, so what changed since the dry run is respected', function () {
        [$listed] = purgeDemoAccount('Listed');
        [$heldLater, $heldLaterShop] = purgeDemoAccount('Joined Later');
        $dryRun = purgeDemoRun();
        expect($dryRun)->toContain($listed->email)->toContain($heldLater->email);

        [$madeAfter, $madeAfterShop] = purgeDemoAccount('Made After');
        $member = memberAs('collector', $heldLaterShop);

        $output = purgeDemoRun(['--confirm' => true]);

        expect(User::find($listed->id))->toBeNull()
            ->and(User::find($madeAfter->id))->toBeNull()->and(Tenant::find($madeAfterShop->id))->toBeNull()
            ->and(User::find($heldLater->id))->not->toBeNull()->and(Tenant::find($heldLaterShop->id))->not->toBeNull()
            ->and(User::find($member->id))->not->toBeNull()
            ->and($output)->toContain('Removed 2 demo accounts');
    });

    it('needs --include-test to remove staff test workspaces, and keeps the staff member', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $test = app(TestWorkspace::class)->open($admin);

        $without = purgeDemoRun(['--confirm' => true]);
        expect(Tenant::find($test->id))->not->toBeNull()
            ->and($without)->toContain('Nothing to remove')->toContain('--include-test');

        $with = purgeDemoRun(['--confirm' => true, '--include-test' => true]);
        expect(Tenant::find($test->id))->toBeNull()
            ->and(User::find($admin->id))->not->toBeNull()
            ->and($with)->toContain('Removed 1 staff test workspace.')
            ->and(AuditLog::where('action', 'demo.purged')->sole()->changes)->toBe(['accounts' => 0, 'workspaces' => 0, 'test_workspaces' => 1]);
    });

    it('is harmless the second time, and says there was nothing to remove', function () {
        purgeDemoAccount();
        purgeDemoAccount();
        expect(purgeDemoRun(['--confirm' => true]))->toContain('Removed 2 demo accounts');
        $before = purgeDemoRowCounts();

        $exit = Artisan::call('qistas:purge-demo', ['--confirm' => true]);
        $second = Artisan::output();

        expect($exit)->toBe(0)
            ->and($second)->toContain('Nothing to remove')->not->toContain('Removed')
            ->and(purgeDemoRowCounts())->toBe($before)
            // One record for each of the two removals of the first run, none for the second run.
            ->and(AuditLog::where('action', 'demo.purged')->count())->toBe(2);
    });

    it('leaves the go-live check with no old demo accounts', function () {
        purgeDemoAccount();
        $this->seed(DemoSeeder::class);
        $demoData = fn () => collect(GoLiveCheck::run())->firstWhere('key', 'demo-data')['status'];
        expect($demoData())->toBe('fail');

        purgeDemoRun(['--confirm' => true]);

        expect($demoData())->toBe('pass');
    });

    it('exits with 0', function () {
        purgeDemoAccount();

        $this->artisan('qistas:purge-demo', ['--confirm' => true])->assertExitCode(0);
    });
});

describe('what the command is', function () {
    it('only a wrapper: it holds no query and no delete of its own', function () {
        $source = file_get_contents(app_path('Console/Commands/PurgeDemoCommand.php'));

        expect($source)->toContain('DemoCleanup')
            ->not->toContain('DB::')->not->toContain('->delete(')->not->toContain('::query()')->not->toContain('Storage::');
    });
});
