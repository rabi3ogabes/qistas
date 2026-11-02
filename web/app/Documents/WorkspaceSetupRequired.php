<?php

namespace App\Documents;

use App\Http\ApiErrors;
use App\Http\ApiException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Name your business first." Every document carries the workspace's name, so none is made while the owner has not
 * confirmed it on the first-run card (DocumentService::issue is the one place that checks). An answer for the person,
 * not a failure: nothing is logged. The app gets HTTP 409 `workspace_setup_required` in the API's usual error shape and
 * opens the card; a browser goes back to the dashboard, where the card is, with the same words.
 */
final class WorkspaceSetupRequired extends RuntimeException implements ShouldntReport
{
    public const CODE = 'workspace_setup_required';

    public function __construct()
    {
        parent::__construct(__('Name your business first. It is printed on every receipt.'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*')) {
            // ApiErrors answers every API request; the fallback only satisfies its nullable return type.
            return ApiErrors::render(new ApiException(self::CODE, $this->getMessage(), 409), $request)
                ?? response()->json(['error' => ['code' => self::CODE, 'message' => $this->getMessage()]], 409);
        }

        return redirect()->route('app.dashboard')->with('warning', $this->getMessage());
    }
}
