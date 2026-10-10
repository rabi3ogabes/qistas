<?php

namespace App\Entitlements;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The platform has this feature switched off (or in a beta this workspace is not part of). Nothing can be bought to
 * change that, so there is no upgrade to offer and the answer is HTTP 403 `feature_unavailable`, never 402.
 */
final class FeatureUnavailable extends EntitlementException
{
    public function errorCode(): string
    {
        return 'feature_unavailable';
    }

    public function status(): int
    {
        return 403;
    }

    protected function describe(): string
    {
        return __('That is not available right now.');
    }

    /** No limit, no usage, and above all no upgrade link: it would promise something a plan cannot deliver. */
    public function payload(): array
    {
        return ['code' => $this->errorCode(), 'message' => $this->getMessage(), 'feature' => $this->feature->value];
    }

    /**
     * A form sent to a switched-off feature goes back to where it was, with the message and what was typed; a page of
     * one shows the plain "not available" page. (The framework's own `errors::` views only exist inside its error
     * handler, so this page is the application's own.)
     */
    protected function renderForBrowser(): RedirectResponse|Response
    {
        if (! request()->isMethodSafe()) {
            return back()->withInput()->with('error', $this->getMessage());
        }

        return response()->view('errors.feature-unavailable', [], 403);
    }
}
