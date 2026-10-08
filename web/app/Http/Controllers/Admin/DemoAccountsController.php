<?php

namespace App\Http\Controllers\Admin;

use App\Sandbox\DemoAccess;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Housekeeping for the demo buttons: clear the accounts whose time is up, now rather than at the next press. */
final class DemoAccountsController
{
    public function prune(Request $request, DemoAccess $demo): RedirectResponse
    {
        $removed = 0;

        do {
            $batch = $demo->prune(100);
            $removed += $batch;
        } while ($batch === 100);

        Audit::record('admin.demo_pruned', changes: ['removed' => $removed], userId: $request->user()->id);

        $message = match (true) {
            $removed === 0 => __('No expired demo accounts to clear.'),
            $removed === 1 => __('Cleared 1 expired demo account.'),
            default => __('Cleared :count expired demo accounts.', ['count' => $removed]),
        };

        return redirect()->route('admin.home')->with('status', $message);
    }
}
