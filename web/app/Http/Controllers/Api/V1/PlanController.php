<?php

namespace App\Http\Controllers\Api\V1;

use App\Site\PlanOffer;
use App\Site\PricingCatalog;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/** What is on offer, exactly as the admin set it up, in the reader's language. Public: it is what the pricing page shows. */
final class PlanController
{
    public function index(PricingCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->offers()->map(fn (PlanOffer $offer) => [
            'key' => $offer->plan->key,
            'name' => $offer->plan->name,
            'description' => $offer->plan->description,
            'is_free' => $offer->isFree(),
            'currency' => $offer->plan->currency,
            'monthly_price' => $offer->monthlyPrice() === null ? null : Money::add($offer->monthlyPrice(), '0', 2),
            'yearly_price' => $offer->yearlyPrice() === null ? null : Money::add($offer->yearlyPrice(), '0', 2),
            'yearly_saving_percent' => $offer->yearlySavingPercent(),
            'features' => collect($offer->features())->mapWithKeys(fn (array $row) => [$row['feature']->value => [
                'type' => $row['feature']->type()->value,
                'label' => $row['feature']->label(),
                'enabled' => $row['enabled'],
                'limit' => $row['limit'],
                'summary' => $row['summary'],
            ]])->all(),
        ])->values()]);
    }
}
