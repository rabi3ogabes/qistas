<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Paperwork\CreateSignatureLink;
use App\Actions\Paperwork\SignContract;
use App\Http\Resources\SignatureResource;
use App\Models\Contract;
use App\Support\SignatureImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's signature (Win Plan K5, CT23, decision D6): drawn on this phone (multipart `file`, a PNG), or a link
 * for the customer to sign on theirs, with its code. The signature is listed with the contract. Owners, managers and
 * accountants, with the switch on.
 */
final class SignatureController
{
    public function store(Request $request, Contract $contract, SignContract $sign): JsonResponse
    {
        Gate::authorize('addPaperwork', $contract);
        $request->validate(['file' => ['required', 'file', 'max:'.(SignatureImage::MAX_BYTES / 1024)]]);

        $signature = $sign->handle($contract, (string) file_get_contents((string) $request->file('file')?->getRealPath()), $request->user());

        return (new SignatureResource($signature))->response()->setStatusCode(201);
    }

    /** The link and the code are in this answer and nowhere else: only their hashes are kept. */
    public function link(Request $request, Contract $contract, CreateSignatureLink $create): JsonResponse
    {
        Gate::authorize('addPaperwork', $contract);
        ['link' => $link, 'url' => $url, 'code' => $code] = $create->handle($contract, $request->user());

        return response()->json(['data' => [
            'url' => $url,
            'code' => $code,
            'expires_at' => $link->expires_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'phone' => $contract->customer?->phone,
        ]], 201)->header('Cache-Control', 'no-store, private');
    }
}
