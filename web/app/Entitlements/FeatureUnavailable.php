<?php

namespace App\Entitlements;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    protected function renderForBrowser(): Response
    {
        return response()->view('errors::403', ['exception' => new HttpException(403, $this->getMessage())], 403);
    }
}
