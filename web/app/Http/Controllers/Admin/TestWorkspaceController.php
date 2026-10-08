<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Sandbox\TestWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The buttons of the admin's test tools. Each one is a plain form post that answers with a redirect. */
final class TestWorkspaceController
{
    public function __construct(private readonly TestWorkspace $test) {}

    /** "Test the dashboard": into the sandbox, which is made on the first visit. */
    public function open(Request $request): RedirectResponse
    {
        $this->test->open($request->user());

        // "Test the app" turns test mode on and stays on the admin page, where the app is.
        if ($request->input('to') === 'admin') {
            return redirect()->route('admin.home')->with('status', __('Test mode is on. Sign in to the app to try it with sample data.'));
        }

        return redirect()->route('app.dashboard')->with('status', __('You are in the test workspace. Everything here is sample data.'));
    }

    /** "Start again": a fresh sandbox with the sample data back. */
    public function reset(Request $request): RedirectResponse
    {
        $this->test->reset($request->user());

        return redirect()->route('app.dashboard')->with('status', __('The test workspace was reset to its sample data.'));
    }

    /** "Leave test mode": back to the admin's own workspace, or to the admin home. */
    public function leave(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $this->test->leave($admin);

        return ($admin->fresh()->primaryTenant() === null ? redirect()->route('admin.home') : redirect()->route('app.dashboard'))
            ->with('status', __('You left the test workspace.'));
    }

    /** Free to feel the limits, Pro to feel their absence. */
    public function plan(Request $request): RedirectResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', Rule::exists('plans', 'key')]]);

        if (! $this->test->usePlan($request->user(), $data['plan'])) {
            return redirect()->route('admin.home');
        }

        return back()->with('status', __('The test workspace is now on the :plan plan.', ['plan' => Plan::where('key', $data['plan'])->value('name')]));
    }
}
