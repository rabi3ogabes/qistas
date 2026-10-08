<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Reports\PlatformOverview;
use App\Sandbox\TestWorkspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The admin's front door: the platform at a glance, the test tools, and whether the site is set up. */
final class HomeController
{
    public function show(Request $request, TestWorkspace $test, PlatformOverview $overview): View
    {
        $admin = $request->user();

        return view('admin.home', [
            'admin' => $admin,
            'figures' => $overview->figures(),
            'health' => $overview->health(),
            'sandbox' => $test->for($admin),
            'testOn' => $test->isOn($admin),
            'plans' => Plan::query()->orderBy('sort_order')->get(['key', 'name']),
            'apiUrl' => url('/api/v1'),
            'appDownloadUrl' => config('qistas.app_download_url'),
        ]);
    }
}
