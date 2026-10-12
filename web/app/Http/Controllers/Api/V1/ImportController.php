<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureStatus;
use App\Http\Resources\ImportResource;
use App\Import\Ai\AiSettings;
use App\Import\ApplyImport;
use App\Import\Fields;
use App\Import\ImportTemplate;
use App\Import\StartImport;
use App\Models\Import;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bringing a shop's books in (Win Plan K9): a file makes a trial run (POST /imports), its columns can be chosen again
 * (PUT), and one confirm brings the rows without a problem in (POST /imports/{import}/confirm). Owners and managers,
 * with the import feature on.
 */
final class ImportController
{
    public function store(Request $request, StartImport $start): JsonResponse
    {
        Gate::authorize('import');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $tenant = app(CurrentTenant::class)->get() ?? abort(404);

        return (new ImportResource($start->handle($tenant, $request->file('file'), $request->user())))->response()->setStatusCode(201);
    }

    public function show(Import $import): ImportResource
    {
        Gate::authorize('import');

        return new ImportResource($import);
    }

    public function update(Request $request, Import $import, StartImport $start): ImportResource
    {
        Gate::authorize('import');
        $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', Rule::in(Fields::keys())],
        ]);

        return new ImportResource($start->remap($import, (array) $request->input('mapping')));
    }

    public function confirm(Request $request, Import $import, ApplyImport $apply): ImportResource
    {
        Gate::authorize('import');

        return new ImportResource($apply->handle($import, $request->user()));
    }

    /**
     * What this workspace may bring in: the rows a file may have, and whether photos and PDFs are read (available: the
     * key is saved and the feature is on; included: the plan has it) with the pages left this month.
     */
    public function options(AiSettings $settings): JsonResponse
    {
        Gate::authorize('import');
        $features = Entitlements::for(app(CurrentTenant::class)->get() ?? abort(404));
        $ai = $features->check(Feature::AiReading);

        return response()->json(['data' => [
            'rows_limit' => $features->check(Feature::Import)->limit(),
            'ai' => [
                'available' => $settings->configured() && $ai->status() !== FeatureStatus::PlatformOff,
                'included' => $ai->enabled(),
                'pages_left' => $ai->enabled() ? $ai->remaining() : 0,
            ],
        ]]);
    }

    /** An empty sheet with the column names in [language], to fill in and send back. */
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
