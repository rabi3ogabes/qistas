<?php

namespace App\Entitlements;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Your plan does not allow that." A first-class outcome, not a crash: API clients get HTTP 402 with a
 * machine-readable body; browsers go back with what the upgrade sheet needs.
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

    abstract protected function describe(): string;

    /** @return array{code: string, message: string, feature: string, limit: ?int, used: ?int, upgrade_url: string} */
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

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $this->payload()], 402);
        }

        return back()->with('upgrade', $this->payload());
    }
}
