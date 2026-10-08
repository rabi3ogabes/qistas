<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\AccountPayload;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Who is signed in and what their plan allows right now. Apps call it on start and after anything that may change a plan. */
final class MeController
{
    public function __invoke(Request $request, CurrentTenant $current): JsonResponse
    {
        return response()->json(['data' => AccountPayload::for($request->user(), $current->get())]);
    }
}
