<?php

namespace App\Http\Controllers;

use App\Models\AppearanceAsset;
use Illuminate\Http\Response;

/**
 * Serves a brand picture. A picture never changes once stored (a new upload is a new id), so its address is cached for a
 * year by the browser and the CDN. It is only ever a JPEG or a PNG that this site re-encoded itself.
 */
final class BrandAssetController
{
    public function __invoke(AppearanceAsset $asset): Response
    {
        return response($asset->bytes(), 200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) $asset->size,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'ETag' => '"'.$asset->sha256.'"',
        ]);
    }
}
