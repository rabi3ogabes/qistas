<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Import\ApplyImport;
use App\Import\Fields;
use App\Import\ImportTemplate;
use App\Import\StartImport;
use App\Models\Import;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bringing a shop's books in on the web (Win Plan K9): send a file, read the trial run row by row (choosing a column
 * again if the file named it its own way), then confirm. Owners and managers, with the import feature on.
 */
final class ImportController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(): View
    {
        Gate::authorize('import');
        $tenant = $this->current->get() ?? abort(404);

        return view('app.import.index', [
            'entitlement' => Entitlements::for($tenant)->check(Feature::Import),
            'recent' => Import::query()->latest()->limit(5)->get(['id', 'filename', 'status', 'summary', 'created_at']),
            'fields' => Fields::keys(),
        ]);
    }

    public function store(Request $request, StartImport $start): RedirectResponse
    {
        Gate::authorize('import');
        $request->validate(['file' => ['required', 'file', 'max:10240']], ['file.required' => __('Choose an Excel (.xlsx) or CSV file.')]);

        $import = $start->handle($this->current->get() ?? abort(404), $request->file('file'), $request->user());

        return redirect()->route('app.import.show', $import);
    }

    public function show(Import $import): View
    {
        Gate::authorize('import');

        return view('app.import.show', ['import' => $import, 'fields' => Fields::keys()]);
    }

    public function update(Request $request, Import $import, StartImport $start): RedirectResponse
    {
        Gate::authorize('import');
        $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'string', Rule::in(Fields::keys())]]);

        $start->remap($import, array_map(fn ($field) => $field === '' ? null : $field, (array) $request->input('mapping')));

        return redirect()->route('app.import.show', $import)->with('status', __('Columns saved. The trial run is updated.'));
    }

    public function confirm(Request $request, Import $import, ApplyImport $apply): RedirectResponse
    {
        Gate::authorize('import');
        $done = $apply->handle($import, $request->user());

        return redirect()->route('app.import.show', $done)->with('status', __('Brought in. New customers: :customers. Contracts: :contracts.', [
            'customers' => (int) ($done->summary['customers'] ?? 0),
            'contracts' => (int) ($done->summary['contracts'] ?? 0),
        ]));
    }

    public function template(Request $request): Response
    {
        Gate::authorize('import');
        $language = in_array($request->query('language'), (array) config('qistas.locales'), true) ? (string) $request->query('language') : app()->getLocale();

        return response(ImportTemplate::xlsx($language), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="qistas-customers.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
