<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ExportService;
use App\Http\Requests\ExportRequest;
use App\Models\Tenant;
use App\Models\WorkspaceExport;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backups and data (Win Plan PP10): when the books were last copied and the copies kept, and the whole of the books as
 * one file. Owners, managers and accountants, on any plan.
 */
final class BackupController
{
    public function __construct(private readonly ExportService $exports, private readonly CurrentTenant $current) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $copies = WorkspaceExport::query()->where('status', 'ready')
            ->where(fn ($query) => $query->where('kind', 'nightly')->orWhere('expires_at', '>', now()))
            ->latest('created_at')->get();

        return response()->json([
            'data' => [
                'last_backup_at' => $copies->firstWhere('kind', 'nightly')?->created_at->toIso8601String(),
                'copies' => $copies->map(fn (WorkspaceExport $export) => $export->toSummary())->values(),
            ],
            'meta' => ['can_export' => true, 'kept' => ExportService::NIGHTLY_KEPT],
        ]);
    }

    /** 202: the file is made straight away here, so it is ready; a client that is told "pending" asks again. */
    public function store(ExportRequest $request): JsonResponse
    {
        $export = $this->exports->manual($this->tenant(), $request->exportFormat(), $request->user());

        return response()->json(['data' => $export->toSummary()], 202);
    }

    public function show(Request $request, WorkspaceExport $export): JsonResponse
    {
        $this->allow($request);

        return response()->json(['data' => $export->toSummary()]);
    }

    /** A link to the file that works for five minutes. */
    public function download(Request $request, WorkspaceExport $export): JsonResponse
    {
        $this->allow($request);
        abort_unless($export->status === 'ready', 404);
        $link = $this->exports->link($export);

        return response()->json(['data' => ['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String(), 'filename' => $export->filename()]]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()?->roleIn($this->tenant()->id)?->canExport() ?? false, 403);
    }

    private function tenant(): Tenant
    {
        return $this->current->get() ?? abort(404);
    }
}
