<?php

namespace App\Http\Controllers\Workspace;

use App\Models\StoredFile;
use App\Support\Audit;
use App\Support\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens a stored file: a member of the workspace whose role may see this kind is sent to a link that expires within
 * five minutes. Another workspace's file is a 404 (the tenant scope never finds it); opening an identity document is
 * written to the audit log.
 */
final class FileController
{
    public function show(Request $request, StoredFile $file, Files $files): RedirectResponse
    {
        $role = $request->user()?->roleIn($file->tenant_id);

        abort_unless($role !== null && $file->kind->openedBy($role), 403);

        if ($file->kind->audited()) {
            Audit::record('file.viewed', $file, ['kind' => $file->kind->value]);
        }

        return redirect()->away($files->temporaryUrl($file))->header('Cache-Control', 'no-store, private');
    }
}
