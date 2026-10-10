<?php

namespace App\Actions\Team;

use App\Tenancy\TenantRole;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who may do what to whom in a business's team. The owner manages everyone but themselves; a manager manages the
 * people below managers; nobody else manages anyone. There is one owner, and that role is never handed out.
 */
final class TeamRules
{
    public static function canManage(?TenantRole $role): bool
    {
        return $role === TenantRole::Owner || $role === TenantRole::Manager;
    }

    /** @return list<TenantRole> the roles $by may give to someone */
    public static function assignable(?TenantRole $by): array
    {
        return match ($by) {
            TenantRole::Owner => [TenantRole::Manager, TenantRole::Accountant, TenantRole::Collector, TenantRole::Viewer],
            TenantRole::Manager => [TenantRole::Accountant, TenantRole::Collector, TenantRole::Viewer],
            default => [],
        };
    }

    /** May $by change or remove someone whose role is $target? */
    public static function canTouch(?TenantRole $by, TenantRole $target): bool
    {
        if ($target === TenantRole::Owner) {
            return false;
        }

        return $by === TenantRole::Owner || ($by === TenantRole::Manager && $target !== TenantRole::Manager);
    }

    /** @throws AccessDeniedHttpException */
    public static function deny(): never
    {
        throw new AccessDeniedHttpException(__('You are not allowed to do that.'));
    }
}
