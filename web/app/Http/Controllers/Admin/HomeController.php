<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Sandbox\TestWorkspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The admin's front door: the test tools today, the rest of the console as it is built. */
final class HomeController
{
    public function show(Request $request, TestWorkspace $test): View
    {
        $admin = $request->user();

        return view('admin.home', [
            'admin' => $admin,
            'sandbox' => $test->for($admin),
            'testOn' => $test->isOn($admin),
            'plans' => Plan::query()->orderBy('sort_order')->get(['key', 'name']),
            'apiUrl' => url('/api/v1'),
            'appDownloadUrl' => config('qistas.app_download_url'),
        ]);
    }
}
