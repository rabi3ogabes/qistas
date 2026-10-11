<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WaiveLateFee;
use App\Http\Requests\WaiveLateFeeRequest;
use App\Http\Resources\LateFeeResource;
use App\Models\LateFee;

/** Late fees (Win Plan K4). They are charged by the nightly job and listed with their contract; here one is waived. */
final class LateFeeController
{
    public function waive(WaiveLateFeeRequest $request, LateFee $fee, WaiveLateFee $waive): LateFeeResource
    {
        return new LateFeeResource($waive->handle($fee, $request->reason(), $request->user())->load(['installment', 'waivedBy']));
    }
}
