<?php

namespace App\Support;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureStatus;
use App\Models\DayClose;
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
            // The end of the day (daily-habit wave A, H4): for those who may close it, while the workspace has the feature.
            ...($tenant !== null && Gate::allows('create', DayClose::class) && Entitlements::for($tenant)->check(Feature::DayClose)->enabled()
                ? [['key' => 'day-close', 'route' => 'app.day-close.show', 'icon' => 'calendar', 'label' => __('Day close')]]
                : []),
            // Who funds the business: for the people who see it (never collectors), while the feature is on.
            ...($tenant !== null && Gate::allows('viewAny', Investor::class) && Entitlements::for($tenant)->check(Feature::Investors)->enabled()
                ? [['key' => 'investors', 'route' => 'app.investors.index', 'icon' => 'trendUp', 'label' => __('Investors')]]
                : []),
            // Reports (Win Plan K8): shown on Free too, where the page says what Pro gives; hidden while the platform has them off.
            ...($tenant !== null && Gate::allows('view-reports') && Entitlements::for($tenant)->check(Feature::AdvancedReports)->status() !== FeatureStatus::PlatformOff
                ? [['key' => 'reports', 'route' => 'app.reports', 'icon' => 'chart', 'label' => __('Reports')]]
                : []),
        ];
    }
}
