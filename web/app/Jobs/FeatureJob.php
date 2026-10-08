<?php

namespace App\Jobs;

use App\Entitlements\Feature;
use App\Entitlements\FeatureGate;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued work that belongs to one feature of one workspace. Whatever was true when it was queued, it checks the
 * feature's switch again when it runs: if the feature has been switched off (or the plan no longer includes it, or
 * the workspace is gone) it does nothing and says why in the log. Otherwise it runs inside that workspace.
 *
 * Subclasses say which feature and workspace, and put the work itself in work().
 */
abstract class FeatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    abstract public function feature(): Feature;

    abstract public function tenantId(): string;

    abstract protected function work(): void;

    final public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::find($this->tenantId());

        if ($tenant === null || ! FeatureGate::passes($tenant, $this->feature(), static::class)) {
            return;
        }

        $current->use($tenant, fn () => $this->work());
    }
}
