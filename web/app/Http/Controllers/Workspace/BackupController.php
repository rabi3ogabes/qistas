<?php

namespace App\Http\Controllers\Workspace;

use App\Exports\ExportService;
use App\Http\Requests\ExportRequest;
use App\Models\WorkspaceExport;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Backups & data (Win Plan PP10): the nightly copies, and "Download everything", which makes the file and
 * starts the download in one go. Owners, managers and accountants, on any plan.
 */
final class BackupController
{
    public function __construct(private readonly ExportService $exports, private readonly CurrentTenant $current) {}

    public function show(Request $request): View
    {
        $this->allow($request);
        $copies = WorkspaceExport::query()->where('status', 'ready')
            ->where(fn ($query) => $query->where('kind', 'nightly')->orWhere('expires_at', '>', now()))
            ->latest('created_at')->get();

        return view('app.backups', [
            'copies' => $copies,
            'lastBackup' => $copies->firstWhere('kind', 'nightly')?->created_at,
            'tenant' => $this->current->get(),
            'kept' => ExportService::NIGHTLY_KEPT,
        ]);
    }

    public function store(ExportRequest $request): RedirectResponse
    {
        $export = $this->exports->manual($this->current->get() ?? abort(404), $request->exportFormat(), $request->user());

        return redirect()->away($this->exports->link($export)['url']);
    }

    public function download(Request $request, WorkspaceExport $export): RedirectResponse
    {
        $this->allow($request);

        return redirect()->away($this->exports->link($export)['url']);
    }

    private function allow(Request $request): void
    {
        $tenantId = $this->current->id();
        abort_unless($tenantId !== null && ($request->user()?->roleIn($tenantId)?->canExport() ?? false), 403);
    }
}
