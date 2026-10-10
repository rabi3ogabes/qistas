<?php

namespace App\Actions\Account;

use App\Auth\SecondFactor;
use App\Http\ApiException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * "Delete my account", as the app stores require it. The person proves who they are (password, and the code if
 * two-step sign-in is on).
 *
 *  - The owner deletes the business: it turns read-only at once for everyone in it and is erased after
 *    DAYS_TO_RESTORE days (PurgeDeletedAccounts), unless the owner restores it first. The owner types the business's
 *    name, so it never happens by a slip.
 *  - Anyone else deletes only their own login, at once: they leave the business, and their login is erased unless they
 *    still belong to another business.
 */
final class RequestAccountDeletion
{
    public const DAYS_TO_RESTORE = 30;

    public function __construct(private readonly SecondFactor $secondFactor) {}

    /**
     * @param  array{password?: mixed, code?: mixed, recovery_code?: mixed, confirm_name?: mixed}  $input
     * @return array{scope: 'workspace', restore_until: string}|array{scope: 'login'}
     *
     * @throws ValidationException when the password or the typed name is wrong
     * @throws ApiException when the second step is missing or wrong
     */
    public function handle(User $user, Tenant $tenant, array $input): array
    {
        if (! Hash::check((string) ($input['password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is not right.')]);
        }

        $this->secondFactor->verify($user, $this->text($input['code'] ?? null), $this->text($input['recovery_code'] ?? null));

        return $user->roleIn($tenant->id) === TenantRole::Owner
            ? $this->deleteWorkspace($user, $tenant, trim((string) ($input['confirm_name'] ?? '')))
            : $this->deleteLogin($user, $tenant);
    }

    /** @return array{scope: 'workspace', restore_until: string} */
    private function deleteWorkspace(User $owner, Tenant $tenant, string $typedName): array
    {
        if ($typedName !== trim($tenant->name)) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the business name exactly as it is shown.')]);
        }

        if (! $tenant->isBeingDeleted()) {
            DB::transaction(function () use ($owner, $tenant): void {
                $tenant->forceFill(['deletion_requested_at' => now(), 'delete_after' => now()->addDays(self::DAYS_TO_RESTORE)])->save();

                DB::table('account_deletions')->insert([
                    'id' => (string) str()->uuid(),
                    'tenant_id' => $tenant->id,
                    'user_id' => $owner->id,
                    'scope' => 'workspace',
                    'requested_at' => now(),
                    'delete_after' => $tenant->delete_after,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Audit::record('account.deletion_requested', $tenant, ['delete_after' => $tenant->delete_after?->toIso8601String()], tenantId: $tenant->id, userId: $owner->id);
            });
        }

        return ['scope' => 'workspace', 'restore_until' => (string) $tenant->restoreUntil()];
    }

    /** @return array{scope: 'login'} */
    private function deleteLogin(User $user, Tenant $tenant): array
    {
        DB::transaction(function () use ($user, $tenant): void {
            $tenant->users()->detach($user->id);
            Audit::record('account.login_deleted', $user, tenantId: $tenant->id, userId: $user->id);

            $elsewhere = $user->tenants()->first();
            if ($elsewhere === null && ! $user->isPlatformAdmin()) {
                DB::table('account_deletions')->insert([
                    'id' => (string) str()->uuid(),
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'scope' => 'login',
                    'requested_at' => now(),
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                PurgeDeletedAccounts::eraseUser($user);

                return;
            }

            // Still part of another business: carry on there.
            if ($user->current_tenant_id === $tenant->id) {
                $user->forceFill(['current_tenant_id' => $elsewhere?->id])->save();
            }
        });

        return ['scope' => 'login'];
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
