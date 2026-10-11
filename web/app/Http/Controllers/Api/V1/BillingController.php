<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\BillingState;
use App\Billing\StoreSubscriptions;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace's plan and how to buy one in the app (Win Plan PP13). A purchase is checked with Google Play before
 * anything turns on; the owner alone buys.
 */
final class BillingController
{
    public function __construct(private readonly CurrentTenant $current, private readonly BillingState $state) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state->for(($this->current->get() ?? abort(403)), $request->user())]);
    }

    public function verifyPlay(Request $request, StoreSubscriptions $store): JsonResponse
    {
        $tenant = ($this->current->get() ?? abort(403));
        abort_unless(BillingState::canBuy($tenant, $request->user()), 403);
        Entitlements::for($tenant)->assertEnabled(Feature::StoreBilling);
        $data = $request->validate([
            'product_id' => ['required', 'string', 'max:100'],
            'purchase_token' => ['required', 'string', 'max:2000'],
        ]);

        $store->verify($tenant, $data['product_id'], $data['purchase_token'], $request->user());

        return response()->json(['data' => $this->state->for($tenant->fresh() ?? $tenant, $request->user())]);
    }
}
