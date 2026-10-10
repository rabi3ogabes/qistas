<?php

namespace App\Notifications\Alerts;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantRole;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Who in a workspace hears about instalments (everyone who collects or keeps the books; never a viewer), and when. */
final class Recipients
{
    /** @return Collection<int, User> */
    public static function of(Tenant $tenant): Collection
    {
        $roles = array_map(fn (TenantRole $role) => $role->value, array_filter(TenantRole::cases(), fn (TenantRole $role) => $role->canWrite()));

        return $tenant->users()->wherePivotIn('role', $roles)->get();
    }

    /**
     * A person's alerts go out from the time they chose for their morning summary and for four hours after it, on the
     * workspace's clock and never past midnight: a scheduler that was down all morning does not wake anyone at night.
     */
    public static function isTime(string $time, CarbonInterface $local): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $from = $local->copy()->setTime($hour, $minute);
        $until = $from->copy()->addHours(4)->min($local->copy()->endOfDay());

        return $local->greaterThanOrEqualTo($from) && $local->lessThan($until);
    }
}
