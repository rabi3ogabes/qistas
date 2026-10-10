<?php

namespace App\Http\Controllers;

use App\Documents\BusinessProfile;
use App\Documents\DocumentService;
use Illuminate\Http\Response;

/**
 * What the QR code on a statement, report or receipt opens (Win Plan PP8): proof that the shop issued it, and nothing a
 * stranger holding the paper should not learn. Only the shop, what it was, its reference, the day and the total; never
 * the customer. Search engines are told to leave it alone.
 */
final class DocumentVerificationController
{
    public function show(string $code): Response
    {
        $document = DocumentService::verify($code) ?? abort(404);
        $tenant = $document->tenant;

        return response()->view('site.verify', [
            'shop' => BusinessProfile::for($tenant)->name(app()->getLocale()),
            'kind' => $document->kind,
            'reference' => $document->reference,
            'issuedAt' => $tenant->localTime($document->created_at),
            'total' => $document->total,
            'currency' => (string) $tenant->currency,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
