<?php

namespace App\Http\Controllers;

use App\Exports\ExportService;
use Illuminate\Http\Response;

/**
 * Where a five-minute link to an export leads (the `signed` middleware has already checked the link). It works without
 * a session, so the phone can download the file directly.
 */
final class ExportFileController
{
    public function show(string $export, ExportService $exports): Response
    {
        $file = ExportService::findForLink($export) ?? abort(404);

        return response($exports->bytes($file), 200, [
            'Content-Type' => $file->mime(),
            'Content-Disposition' => 'attachment; filename="'.$file->filename().'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
