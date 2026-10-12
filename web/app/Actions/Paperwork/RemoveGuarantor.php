<?php

namespace App\Actions\Paperwork;

use App\Models\Guarantor;
use App\Models\User;
use App\Support\Audit;

/**
 * Takes a guarantor off a contract (Win Plan K5). Works whether the feature is on or off: what was added can always
 * be taken away. The audit row keeps that it happened, not who they were.
 */
final class RemoveGuarantor
{
    public function handle(Guarantor $guarantor, User $by): void
    {
        $guarantor->delete();

        Audit::record('guarantor.removed', $guarantor, ['contract_id' => $guarantor->contract_id], $guarantor->tenant_id, $by->id);
    }
}
