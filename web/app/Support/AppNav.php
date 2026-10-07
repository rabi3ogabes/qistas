<?php

namespace App\Support;

/** The sections of the signed-in app, in the order they appear in the sidebar and the phone tab bar. */
final class AppNav
{
    /** @return list<array{key: string, route: string, icon: string, label: string}> */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'route' => 'app.dashboard', 'icon' => 'home', 'label' => __('Dashboard')],
            ['key' => 'customers', 'route' => 'app.customers.index', 'icon' => 'users', 'label' => __('Customers')],
        ];
    }
}
