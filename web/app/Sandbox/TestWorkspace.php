<?php

namespace App\Sandbox;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The admin's sandbox: a workspace with sample data, marked as a test, that a platform admin opens to try the
 * dashboard and the app exactly as a customer would, without registering and without touching real data.
 *
 * It is a real workspace in every other respect (same actions, same plan limits, same API), so what is tried here
 * behaves as it will for customers. One per admin; "start again" replaces it. It never appears in any customer's
 * view, and everything here is refused to anyone who is not platform staff.
 *
 * Starting again deletes the workspace and everything in it in one go. If the ledger ever gets database triggers
 * that forbid deleting rows, this is the one place that must learn to step around them for test workspaces.
 */
final class TestWorkspace
{
    public function __construct(private readonly SampleBusiness $sample) {}

    /** The admin's sandbox, if they have made one. */
    public function for(User $admin): ?Tenant
    {
        $this->authorize($admin);

        return $admin->tenants()->where('tenants.is_test', true)->first();
    }

    /** Is the admin working in their sandbox right now? */
    public function isOn(User $admin): bool
    {
        $test = $this->for($admin);

        return $test !== null && $admin->current_tenant_id === $test->id;
    }

    /** Take the admin into their sandbox, making it (with sample data) the first time. */
    public function open(User $admin): Tenant
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin): Tenant {
            $tenant = $this->for($admin) ?? $this->create($admin);
            $this->enter($admin, $tenant);
            Audit::record('admin.test_workspace.opened', $tenant, tenantId: $tenant->id, userId: $admin->id);

            return $tenant;
        });
    }

    /** Throw the sandbox away and make a fresh one with the sample data again, on the same plan. */
    public function reset(User $admin): Tenant
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($admin): Tenant {
            $old = $this->for($admin);
            $plan = $old?->currentPlan();

            if ($old !== null) {
                $admin->forceFill(['current_tenant_id' => null])->save();
                $old->delete();
            }

            $tenant = $this->create($admin, $plan);
            $this->enter($admin, $tenant);
            Audit::record('admin.test_workspace.reset', $tenant, tenantId: $tenant->id, userId: $admin->id);

            return $tenant;
        });
    }

    /** Back to the admin's own workspace, or to none (then the admin home). The sandbox is kept for next time. */
    public function leave(User $admin): void
    {
        $this->authorize($admin);

        $admin->forceFill(['current_tenant_id' => null])->save();
        Audit::record('admin.test_workspace.left', userId: $admin->id);
    }

    /** Put the sandbox on a plan, to try its limits (Free) or the lack of them (Pro). False without a sandbox. */
    public function usePlan(User $admin, string $planKey): bool
    {
        $test = $this->for($admin);

        if ($test === null) {
            return false;
        }

        $plan = Plan::query()->where('key', $planKey)->firstOrFail();
        $test->subscribeTo($plan);
        Audit::record('admin.test_workspace.plan', $test, ['plan' => $plan->key], tenantId: $test->id, userId: $admin->id);

        return true;
    }

    private function create(User $admin, ?Plan $plan = null): Tenant
    {
        $country = 'SA';

        $tenant = new Tenant([
            'name' => 'Al-Fares Electronics (test)',
            'slug' => 'test-'.Str::lower(Str::random(10)),
            'country' => $country,
            'currency' => config("qistas.countries.{$country}", config('qistas.currency_default')),
        ]);
        $tenant->owner_user_id = $admin->id;
        $tenant->is_test = true;
        $tenant->save();

        $tenant->users()->attach($admin->id, ['role' => 'owner']);
        $tenant->subscribeTo($plan ?? Plan::default());

        $this->sample->populate($tenant, $admin);

        return $tenant;
    }

    private function enter(User $admin, Tenant $tenant): void
    {
        $admin->forceFill(['current_tenant_id' => $tenant->id])->save();
    }

    private function authorize(User $admin): void
    {
        if (! $admin->isPlatformAdmin()) {
            throw new AuthorizationException('Only platform staff may use the test workspace.');
        }
    }
}
