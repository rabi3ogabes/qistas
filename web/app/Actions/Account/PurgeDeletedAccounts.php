<?php

namespace App\Actions\Account;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Files;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erases every business whose 30 days to restore are over: its records (the database cascades from the tenant), its
 * stored files, and the logins of people who belonged to nothing else. Platform staff keep their login (staff access
 * is removed with `qistas:make-admin --revoke`, not here).
 *
 * The audit log is append-only, but personal detail is not kept after an erasure: its rows for the business keep the
 * action and the ids, and lose what changed, the IP address and the browser. `account_deletions` keeps the bare fact.
 * Run daily by the scheduler (`qistas:purge-deleted-accounts`).
 */
final class PurgeDeletedAccounts
{
    /** @return int how many businesses were erased */
    public function handle(int $limit = 20): int
    {
        $due = Tenant::query()->whereNotNull('delete_after')->where('delete_after', '<=', now())->limit($limit)->get();

        $due->each(fn (Tenant $tenant) => $this->erase($tenant));

        return $due->count();
    }

    private function erase(Tenant $tenant): void
    {
        $memberIds = $tenant->users()->pluck('users.id')->all();

        DB::transaction(function () use ($tenant): void {
            DB::table('audit_logs')->where('tenant_id', $tenant->id)->update(['changes' => null, 'ip' => null, 'user_agent' => null]);
            DB::table('account_deletions')->where('tenant_id', $tenant->id)->where('scope', 'workspace')
                ->whereNull('cancelled_at')->whereNull('completed_at')
                ->update(['completed_at' => now(), 'updated_at' => now()]);

            // Contracts, instalments, payments, files' rows, settings and memberships go with it (foreign keys cascade).
            $tenant->delete();
        });

        Storage::disk(Files::DISK)->deleteDirectory("tenants/{$tenant->id}");

        User::query()->whereIn('id', $memberIds)->get()
            ->filter(fn (User $user) => ! $user->isPlatformAdmin() && ! $user->tenants()->exists())
            ->each(fn (User $user) => self::eraseUser($user));
    }

    /** A login and everything that keeps it signed in. */
    public static function eraseUser(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->delete();
    }
}
