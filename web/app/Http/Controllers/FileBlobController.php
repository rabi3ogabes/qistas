<?php

namespace App\Http\Controllers;

use App\Support\Files;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a file of the private disk kept in the database (Win Plan D5). The `signed` middleware has already checked
 * that the link was made by Files::temporaryUrl for this very path and has not expired (five minutes at most), exactly
 * as a bucket checks its own links; nothing else opens these bytes.
 */
final class FileBlobController
{
    public function __invoke(string $path): Response
    {
        $disk = Storage::disk(Files::DISK);
        abort_unless($disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => (string) $disk->mimeType($path),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
