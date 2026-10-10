<?php

namespace App\Support;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Investor;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;

/** The sections of the signed-in app, in the order they appear in the sidebar and the phone tab bar. */
final class AppNav
{
    /** @return list<array{key: string, route: string, icon: string, label: string}> */
    public static function items(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return [
            ['key' => 'dashboard', 'route' => 'app.dashboard', 'icon' => 'home', 'label' => __('Dashboard')],
            ['key' => 'customers', 'route' => 'app.customers.index', 'icon' => 'users', 'label' => __('Customers')],
            ['key' => 'contracts', 'route' => 'app.contracts.index', 'icon' => 'fileText', 'label' => __('Contracts')],
            ['key' => 'payments', 'route' => 'app.payments.index', 'icon' => 'wallet', 'label' => __('Payments')],
            // Who funds the business: for the people who see it (never collectors), while the feature is on.
            ...($tenant !== null && Gate::allows('viewAny', Investor::class) && Entitlements::for($tenant)->check(Feature::Investors)->enabled()
                ? [['key' => 'investors', 'route' => 'app.investors.index', 'icon' => 'trendUp', 'label' => __('Investors')]]
                : []),
        ];
    }
}
