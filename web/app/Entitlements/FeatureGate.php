<?php

namespace App\Entitlements;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * The question a job, a command or an action asks right before it does a feature's work: "is this feature on for this
 * workspace now?" It is answered at run time, never when the work was queued, so a switch turned off yesterday stops
 * what was waiting to run today.
 */
final class FeatureGate
{
    public static function allows(Tenant $tenant, Feature $feature): bool
    {
        return Entitlements::for($tenant)->check($feature)->enabled();
    }

    /**
     * Like allows(), and when the answer is no it leaves a note in the log (feature, workspace and who asked; nothing
     * about people) so "why did it not run?" has an answer.
     *
     * @param  string  $by  what was about to run, e.g. the job's class
     */
    public static function passes(Tenant $tenant, Feature $feature, string $by): bool
    {
        if (self::allows($tenant, $feature)) {
            return true;
        }

        Log::info('skipped: feature_off', ['feature' => $feature->value, 'tenant' => $tenant->id, 'by' => $by]);

        return false;
    }
}
