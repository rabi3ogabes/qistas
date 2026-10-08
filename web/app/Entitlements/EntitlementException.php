<?php

namespace App\Entitlements;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * "You may not do that here." A first-class outcome, not a crash. There are two kinds, and they are never confused:
 * the plan does not allow it (HTTP 402: the client offers an upgrade), or the platform has the feature switched off
 * (HTTP 403: nothing to buy, so no upgrade is offered). API clients get a machine-readable body; browsers go back
 * with what the upgrade sheet needs, or see a plain "not available" page.
 */
abstract class EntitlementException extends RuntimeException
{
    public function __construct(
        public readonly Feature $feature,
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
    ) {
        parent::__construct($this->describe());
    }

    abstract public function errorCode(): string;

    /** The HTTP status this refusal answers with. */
    abstract public function status(): int;

    abstract protected function describe(): string;

    /** @return array{code: string, message: string, feature: string, limit: ?int, used: ?int, upgrade_url: string}|array{code: string, message: string, feature: string} */
    public function payload(): array
    {
        return [
            'code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'feature' => $this->feature->value,
            'limit' => $this->limit,
            'used' => $this->used,
            'upgrade_url' => url('/app/billing'),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse|Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $this->payload()], $this->status());
        }

        return $this->renderForBrowser();
    }

    /** What a person in a browser sees: by default, back to where they were with what the upgrade sheet needs. */
    protected function renderForBrowser(): RedirectResponse|Response
    {
        return back()->with('upgrade', $this->payload());
    }
}
