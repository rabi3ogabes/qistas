<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Paperwork\RemoveGuarantor;
use App\Actions\Paperwork\SaveGuarantor;
use App\Http\Requests\GuarantorRequest;
use App\Http\Resources\GuarantorResource;
use App\Models\Contract;
use App\Models\Guarantor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A contract's guarantors (Win Plan K5, CT21). They are listed with the contract (GET /contracts/{contract}); here
 * one is added (owners, managers, accountants), changed, or removed (owners and managers).
 */
final class GuarantorController
{
    public function store(GuarantorRequest $request, Contract $contract, SaveGuarantor $save): JsonResponse
    {
        Gate::authorize('addPaperwork', $contract);

        return (new GuarantorResource($save->add($contract, $request->guarantor(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(GuarantorRequest $request, Guarantor $guarantor, SaveGuarantor $save): GuarantorResource
    {
        Gate::authorize('addPaperwork', $guarantor->contract);

        return new GuarantorResource($save->update($guarantor, $request->guarantor(), $request->user()));
    }

    public function destroy(Request $request, Guarantor $guarantor, RemoveGuarantor $remove): Response
    {
        Gate::authorize('removePaperwork', $guarantor->contract);
        $remove->handle($guarantor, $request->user());

        return response()->noContent();
    }
}
