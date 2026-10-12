<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StoredFile;
use App\Support\Audit;
use App\Support\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A link to one stored file, for the app (the web's /app/files/{file} redirects to the same link). Only for a member
 * whose role may open this kind; an ID document is written to the audit log. Another workspace's file is a 404.
 */
final class FileController
{
    public function show(Request $request, StoredFile $file, Files $files): JsonResponse
    {
        $role = $request->user()?->roleIn($file->tenant_id);
        abort_unless($role !== null && $file->kind->openedBy($role), 403);

        if ($file->kind->audited()) {
            Audit::record('file.viewed', $file, ['kind' => $file->kind->value]);
        }

        return response()->json(['data' => [
            'url' => $files->temporaryUrl($file),
            'expires_at' => now()->addSeconds(Files::LINK_SECONDS)->toIso8601String(),
            'mime' => $file->mime,
        ]])->header('Cache-Control', 'no-store, private');
    }
}
