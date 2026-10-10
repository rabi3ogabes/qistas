<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ChangePlan;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\Admin\ChangePlanRequest;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every real business on the platform, with its owner and plan, and a super admin putting one on another plan. This is
 * platform-admin code, so it reads across workspaces on purpose, but it only shows a business's name, owner, plan and
 * counts, never its customers or money. Demo accounts and admins' test workspaces are not businesses: they are not
 * listed and cannot be opened here.
 */
final class BusinessController
{
    /** The figures shown for each business: what it uses of its plan. */
    private const USAGE = [Feature::Customers, Feature::ActiveContracts, Feature::PdfStatements, Feature::Members, Feature::Investors];

    public function index(Request $request): View
    {
        $term = mb_strtolower(trim((string) $request->query('q', '')));
        $plans = Plan::query()->orderBy('sort_order')->get();
        $planKey = $plans->firstWhere('key', (string) $request->query('plan'))?->key;

        $businesses = $this->real()
            ->with(['owner', 'subscription.plan'])
            ->addSelect(['customers_count' => DB::table('customers')->selectRaw('COUNT(*)')->whereColumn('customers.tenant_id', 'tenants.id')->whereNull('customers.deleted_at')])
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereRaw('LOWER(tenants.name) LIKE ?', ['%'.$term.'%'])
                ->orWhereHas('owner', fn (Builder $owner) => $owner->whereRaw('LOWER(email) LIKE ?', ['%'.$term.'%']))))
            ->when($planKey !== null, fn (Builder $query) => $this->onPlan($query, $plans->firstWhere('key', $planKey)))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.businesses.index', ['businesses' => $businesses, 'plans' => $plans, 'freePlan' => Plan::default(), 'term' => (string) $request->query('q', ''), 'planKey' => $planKey]);
    }

    public function show(Tenant $tenant): View
    {
        abort_if($tenant->is_demo || $tenant->is_test, 404);

        $entitlements = Entitlements::for($tenant);
        $usage = array_map(fn (Feature $feature) => ['label' => $feature->label(), 'entitlement' => $entitlements->check($feature)], self::USAGE);

        $history = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'admin.plan_changed')->latest('created_at')->limit(50)->get();
        $staff = User::query()->whereIn('id', $history->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return view('admin.businesses.show', [
            'tenant' => $tenant->load(['owner', 'subscription.plan']),
            'plan' => $tenant->currentPlan(),
            'subscription' => $tenant->subscription,
            'members' => $tenant->users()->count(),
            'usage' => $usage,
            'history' => $history,
            'staff' => $staff,
            'plans' => Plan::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function changePlan(ChangePlanRequest $request, Tenant $tenant, ChangePlan $change): RedirectResponse
    {
        abort_if($tenant->is_demo || $tenant->is_test, 404);

        $plan = Plan::query()->where('key', $request->validated('plan'))->sole();
        $change->handle($tenant, $plan, $request->until(), (string) $request->validated('reason'), $request->user());

        return redirect()->route('admin.businesses.show', $tenant)->with('status', __(':business is now on the :plan plan.', ['business' => $tenant->name, 'plan' => $plan->name]));
    }

    /** @return Builder<Tenant> */
    private function real(): Builder
    {
        return Tenant::query()->select('tenants.*')->where('is_demo', false)->where('is_test', false);
    }

    /**
     * Businesses on [$plan] today: a paid plan while its subscription is in force and inside its period (plus the grace
     * days), the free plan otherwise, exactly as Tenant::currentPlan() decides.
     *
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    private function onPlan(Builder $query, Plan $plan): Builder
    {
        $current = fn (Builder $subscription) => $subscription->whereIn('status', Subscription::IN_FORCE)
            ->where(fn (Builder $period) => $period->whereNull('current_period_end')
                ->orWhere('current_period_end', '>', now()->subDays((int) config('qistas.billing.grace_days'))));

        return $plan->is_default
            ? $query->whereDoesntHave('subscription', fn (Builder $subscription) => $current($subscription)->whereHas('plan', fn (Builder $paid) => $paid->where('is_default', false)))
            : $query->whereHas('subscription', fn (Builder $subscription) => $current($subscription)->whereHas('plan', fn (Builder $chosen) => $chosen->whereKey($plan->id)));
    }
}
