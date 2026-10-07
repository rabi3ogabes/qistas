<?php

namespace App\View\Components\Layouts;

use App\Entitlements\Entitlement;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\AppNav;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The frame around every signed-in page: navigation, workspace, plan and usage. */
final class App extends Component
{
    public Tenant $tenant;

    public Plan $plan;

    /** @var array<string, Entitlement> the two limits people watch, for the sidebar meters */
    public array $usage;

    /** @var list<array{key: string, route: string, icon: string, label: string}> */
    public array $nav;

    public function __construct(public string $title, public string $section = 'dashboard')
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        $entitlements = Entitlements::for($tenant);

        $this->tenant = $tenant;
        $this->plan = $tenant->currentPlan();
        $this->usage = [
            'customers' => $entitlements->check(Feature::Customers),
            'contracts' => $entitlements->check(Feature::ActiveContracts),
        ];
        $this->nav = AppNav::items();
    }

    public function render(): View
    {
        return view('components.layouts.app');
    }
}
