<?php

namespace App\Billing;

use App\Models\Tenant;

/**
 * One free trial per workspace in its life (spec 2026-10-27 §6). It counts as used the first time the workspace gets a
 * paid plan through a purchase or a trial: a card trial or payment, a Google Play purchase, an approved bank transfer.
 * A plan the Qistas team gives does not count.
 */
final class Trials
{
    public function available(Tenant $tenant): bool
    {
        return $tenant->trial_used_at === null;
    }

    /** Records the first use; a later purchase never moves the date. */
    public function markUsed(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->getKey())->whereNull('trial_used_at')->update(['trial_used_at' => now()]);
        $tenant->refresh();
    }
}
