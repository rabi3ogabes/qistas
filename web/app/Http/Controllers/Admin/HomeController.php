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
            'sandbox' => $test->for($admin),
            'testOn' => $test->isOn($admin),
            'plans' => Plan::query()->orderBy('sort_order')->get(['key', 'name']),
            'apiUrl' => url('/api/v1'),
            'appDownloadUrl' => config('qistas.app_download_url'),
            // Last on purpose: its read of the error tables is allowed to fail (see PlatformOverview::errorsCheck()), and a
            // failed read inside a transaction (the test suite's, on PostgreSQL) refuses every statement after it.
            'health' => $overview->health(),
        ]);
    }
}
