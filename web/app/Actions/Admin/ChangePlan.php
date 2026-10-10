<?php

namespace App\Actions\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A super admin puts a business on another plan: Pro for a time after a bank transfer, Pro with no end, or back to the
 * free plan. Takes effect on the next request (nothing is cached). Nothing is ever deleted: a business over the free
 * plan's limits keeps everything and only stops adding. Written to the audit log with what it was, what it became and
 * why, against the business, by the admin who did it.
 */
final class ChangePlan
{
    /**
     * @param  CarbonInterface|null  $until  the last day the plan is given (end of that day); null for no end
     *
     * @throws InvalidArgumentException when the business is a demo or test workspace, or the end is not in the future
     */
    public function handle(Tenant $tenant, Plan $plan, ?CarbonInterface $until, string $reason, User $by): Subscription
    {
        if ($tenant->is_demo || $tenant->is_test) {
            throw new InvalidArgumentException('Demo and test workspaces change plan through their own tools.');
        }

        // The free plan never runs out.
        $until = $plan->is_default ? null : $until?->copy()->endOfDay();
        if ($until !== null && ! $until->isFuture()) {
            throw new InvalidArgumentException('A plan given until a day needs a day in the future.');
        }

        return DB::transaction(function () use ($tenant, $plan, $until, $reason, $by): Subscription {
            $before = $this->describe($tenant);
            $subscription = $tenant->subscribeTo($plan, 'active', $until);

            Audit::record('admin.plan_changed', $subscription, [
                'before' => $before,
                'after' => ['plan' => $plan->key, 'until' => $until?->toDateString()],
                'reason' => $reason,
            ], $tenant->id, $by->id);

            return $subscription;
        });
    }

    /** @return array{plan: string, until: string|null} the plan the business is on now, as it would be read today */
    private function describe(Tenant $tenant): array
    {
        $subscription = $tenant->subscription()->with('plan')->first();

        return $subscription?->isCurrent()
            ? ['plan' => $subscription->plan->key, 'until' => $subscription->current_period_end?->toDateString()]
            : ['plan' => Plan::default()->key, 'until' => null];
    }
}
