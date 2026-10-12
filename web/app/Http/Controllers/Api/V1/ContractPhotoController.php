<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Paperwork\AddContractPhoto;
use App\Actions\Paperwork\RemoveContractPhoto;
use App\Http\Requests\ContractPhotoRequest;
use App\Http\Resources\ContractPhotoResource;
use App\Models\Contract;
use App\Models\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A contract's photos (Win Plan K5, CT22). They are listed with the contract; here one is added (multipart `kind` and
 * `file`) or removed. Opening an ID photo is GET /files/{file}.
 */
final class ContractPhotoController
{
    public function store(ContractPhotoRequest $request, Contract $contract, AddContractPhoto $add): JsonResponse
    {
        Gate::authorize('addPaperwork', $contract);

        return (new ContractPhotoResource($add->handle($contract, $request->kind(), $request->photo(), $request->user())))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Contract $contract, StoredFile $photo, RemoveContractPhoto $remove): Response
    {
        Gate::authorize('removePaperwork', $contract);
        $remove->handle($contract, $photo, $request->user());

        return response()->noContent();
    }
}
