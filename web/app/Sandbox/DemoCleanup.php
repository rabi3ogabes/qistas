<?php

namespace App\Sandbox;

use App\Actions\Account\PurgeDeletedAccounts;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Files;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What the old public demo and the developer seeder left behind, and taking it away (`qistas:purge-demo`, go-live basics
 * 6.4). It always plans first: the plan is what the owner reads, and a purge deletes nothing the plan does not name.
 *
 * A demo account is a user whose `demo_expires_at` is set (a "Try the demo" press made it) or whose address is the
 * developer seeder's own (DemoSeeder::EMAIL), and who is not platform staff. Never an address at the demo domain alone:
 * a real person could sign up with one (D1). With it go the workspaces it owns that nobody else belongs to (any demo
 * workspace, and the seeder's sample business, which is not flagged as one). A workspace a real person also belongs to
 * is never removed; its owner is then held back whole, so the workspace keeps the owner it has.
 *
 * Staff test workspaces (`is_test`) are listed under their own heading and removed only when the plan was made with
 * $includeTest.
 *
 * This is platform code and the one place allowed to look across every workspace: finding what is left over means
 * reading users and workspaces that belong to nobody in particular. It reads through the Tenant model and the query
 * builder (neither is tenant-scoped) and never through a tenant-owned model, so no tenant scope is stepped around.
 *
 * @phpstan-type PlannedWorkspace array{id: string, name: string, rows: int}
 * @phpstan-type PlannedAccount array{user_id: string, email: string, workspaces: list<PlannedWorkspace>}
 * @phpstan-type PlannedTestWorkspace array{id: string, name: string, owner_email: string}
 * @phpstan-type Plan array{accounts: list<PlannedAccount>, test_workspaces: list<PlannedTestWorkspace>, include_test?: bool}
 */
final class DemoCleanup
{
    /**
     * The address domain the demo buttons gave their accounts. It is NOT used to find accounts to delete (D1): only to
     * recognise an address that looks like an old demo one, so the go-live check can warn about it.
     */
    public const DOMAIN = 'demo.qistas.test';

    /** The records counted as a workspace's "rows" in the plan: the business data a person would recognise. */
    private const COUNTED = ['customers', 'contracts', 'installments', 'transactions', 'documents', 'day_closes', 'stored_files'];

    /**
     * What a purge would remove right now. Every staff test workspace is listed; `include_test` says whether a purge of
     * this plan removes them.
     *
     * @return Plan
     */
    public function plan(bool $includeTest = false): array
    {
        $demoIds = $this->demoAccountIds();
        $accounts = [];

        foreach (User::query()->whereIn('id', $demoIds)->orderBy('email')->get() as $user) {
            $owned = Tenant::query()->where('owner_user_id', $user->id)->orderBy('name')->get();

            // Held back whole if any workspace it owns must stay: removing the login would leave that one ownerless.
            if ($owned->contains(fn (Tenant $tenant) => ! $this->removable($tenant, $demoIds))) {
                continue;
            }

            $accounts[] = [
                'user_id' => (string) $user->id,
                'email' => (string) $user->email,
                'workspaces' => $owned->map(fn (Tenant $tenant) => [
                    'id' => (string) $tenant->id,
                    'name' => (string) $tenant->name,
                    'rows' => $this->rows($tenant),
                ])->values()->all(),
            ];
        }

        $tests = Tenant::query()->with('owner')->where('is_test', true)->orderBy('name')->get()
            ->map(fn (Tenant $tenant) => [
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'owner_email' => (string) ($tenant->owner->email ?? ''),
            ])->values()->all();

        return ['accounts' => $accounts, 'test_workspaces' => $tests, 'include_test' => $includeTest];
    }

    /**
     * Delete exactly what [$plan] lists, each account in one transaction: its workspaces (Tenant::delete(), whose rows
     * go through the database's cascading foreign keys), then its sessions, its tokens and the login. Everything is
     * checked again first: an account that is no longer a demo account, or owns a workspace the plan did not list or that
     * a real person has joined since, is left exactly as it is. Staff test workspaces go only if the plan includes them.
     *
     * @param  Plan  $plan
     * @return int how many accounts were removed
     */
    public function purge(array $plan): int
    {
        $accounts = 0;
        $workspaces = 0;

        foreach ($plan['accounts'] as $account) {
            $removed = $this->purgeAccount($account);

            if ($removed !== null) {
                $accounts++;
                $workspaces += $removed;
            }
        }

        $tests = 0;

        if (($plan['include_test'] ?? false) === true) {
            foreach ($plan['test_workspaces'] as $workspace) {
                $tests += $this->purgeTestWorkspace($workspace['id']) ? 1 : 0;
            }
        }

        if ($accounts + $tests > 0) {
            Audit::record('demo.purged', changes: ['accounts' => $accounts, 'workspaces' => $workspaces, 'test_workspaces' => $tests]);
        }

        return $accounts;
    }

    /** Only ever a demo account: a demo button's expiring login or the seeder's own, never anyone with a platform role. */
    public static function isDemoAccount(User $user): bool
    {
        return $user->platform_role === null
            && ($user->demo_expires_at !== null || $user->email === DemoSeeder::EMAIL);
    }

    /** @return list<string> the ids of every demo account there is now (the same rule as isDemoAccount, in the database) */
    private function demoAccountIds(): array
    {
        return User::query()
            ->whereNull('platform_role')
            ->where(fn ($query) => $query->whereNotNull('demo_expires_at')->orWhere('email', DemoSeeder::EMAIL))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * A workspace of a demo account may go with it: not a staff test workspace, and nobody but demo accounts in it.
     *
     * @param  list<string>  $demoIds
     */
    private function removable(Tenant $tenant, array $demoIds): bool
    {
        if ($tenant->is_test) {
            return false;
        }

        return DB::table('tenant_users')->where('tenant_id', $tenant->id)->whereNotIn('user_id', $demoIds)->doesntExist();
    }

    /** The workspace's business records: customers, contracts, instalments, ledger lines, documents, days and files. */
    private function rows(Tenant $tenant): int
    {
        $rows = 0;

        foreach (self::COUNTED as $table) {
            $rows += DB::table($table)->where('tenant_id', $tenant->id)->count();
        }

        return $rows;
    }

    /**
     * One account, in one transaction, after checking it all again.
     *
     * @param  PlannedAccount  $account
     * @return int|null how many workspaces went with it, or null when the account was left alone
     */
    private function purgeAccount(array $account): ?int
    {
        $listed = array_column($account['workspaces'], 'id');

        $gone = DB::transaction(function () use ($account, $listed): ?array {
            $user = User::query()->find($account['user_id']);

            if ($user === null || ! self::isDemoAccount($user)) {
                return null;
            }

            $demoIds = $this->demoAccountIds();
            $owned = Tenant::query()->where('owner_user_id', $user->id)->get();

            foreach ($owned as $tenant) {
                if (! in_array($tenant->id, $listed, true) || ! $this->removable($tenant, $demoIds)) {
                    return null;
                }
            }

            $owned->each(fn (Tenant $tenant) => $this->deleteWorkspace($tenant));
            PurgeDeletedAccounts::eraseUser($user);

            return $owned->pluck('id')->all();
        });

        if ($gone === null) {
            return null;
        }

        // Files cannot be rolled back, so they go once the records are gone for good.
        foreach ($gone as $tenantId) {
            Storage::disk(Files::DISK)->deleteDirectory("tenants/{$tenantId}");
        }

        return count($gone);
    }

    private function purgeTestWorkspace(string $id): bool
    {
        $gone = DB::transaction(function () use ($id): bool {
            $tenant = Tenant::query()->find($id);

            if ($tenant === null || ! $tenant->is_test) {
                return false;
            }

            $this->deleteWorkspace($tenant);

            return true;
        });

        if ($gone) {
            Storage::disk(Files::DISK)->deleteDirectory("tenants/{$id}");
        }

        return $gone;
    }

    /**
     * The workspace and everything in it. Its contracts, instalments, payments, documents, files' rows, days and
     * memberships go with it through the database's cascading foreign keys (the ledger refuses edits in the model, not
     * a whole workspace's delete). Nobody is left "working in" it, and its audit rows keep the action and the ids but
     * lose what changed, the address and the browser, as when a business is erased (PurgeDeletedAccounts).
     */
    private function deleteWorkspace(Tenant $tenant): void
    {
        User::query()->where('current_tenant_id', $tenant->id)->update(['current_tenant_id' => null]);
        DB::table('audit_logs')->where('tenant_id', $tenant->id)->update(['changes' => null, 'ip' => null, 'user_agent' => null]);

        $tenant->delete();
    }
}
