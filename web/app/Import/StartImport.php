<?php

namespace App\Import;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Reads a file and makes its trial run (Win Plan K9): nothing is saved but the import itself, which keeps what was read
 * so the columns can be chosen again. A file has at most the rows the plan allows at once (Free: 100).
 */
final class StartImport
{
    public function __construct(private readonly CurrentTenant $current, private readonly SheetReader $reader) {}

    /** @throws ValidationException (field `file`) when it is not a spreadsheet, is empty, or is too long for the plan */
    public function handle(Tenant $tenant, UploadedFile $file, User $by): Import
    {
        $features = Entitlements::for($tenant);
        $features->assertEnabled(Feature::Import);

        $path = (string) $file->getRealPath();
        $kind = $this->kind($path);
        ['headers' => $headers, 'rows' => $cells] = $this->reader->read($path, $kind);

        $limit = $features->check(Feature::Import)->limit();
        if ($limit !== null && count($cells) > $limit) {
            throw ValidationException::withMessages(['file' => __('Your plan brings in up to :count rows at a time. Split the file into smaller ones, or upgrade.', ['count' => $limit])]);
        }

        $mapping = ColumnMatcher::match($headers);
        $plan = ImportPlan::make($tenant, $headers, $cells, $mapping);

        return $this->current->use($tenant, function () use ($kind, $file, $headers, $cells, $mapping, $plan, $by): Import {
            $import = (new Import)->forceFill([
                'source' => $kind,
                'filename' => mb_substr($file->getClientOriginalName() ?: 'import', 0, 255),
                'headers' => $headers,
                'cells' => $cells,
                'mapping' => $mapping,
                'rows' => $plan['rows'],
                'summary' => $plan['summary'],
                'status' => 'trial',
                'created_by_user_id' => $by->id,
            ]);
            $import->save();

            return $import;
        });
    }

    /**
     * The columns chosen by hand: [$choices] maps a column's index to a field, or to null to leave it out. Columns not
     * named keep what they were. A field goes to one column only: choosing it again moves it.
     *
     * @param  array<int|string, string|null>  $choices
     */
    public function remap(Import $import, array $choices): Import
    {
        Entitlements::for($import->tenant)->assertEnabled(Feature::Import);
        if ($import->isDone()) {
            throw ValidationException::withMessages(['mapping' => __('This import is already done.')]);
        }

        $mapping = array_map(fn ($field) => $field, $import->mapping);
        foreach ($choices as $index => $field) {
            $index = (int) $index;
            if (! array_key_exists($index, $import->headers)) {
                continue;
            }
            if ($field !== null) {
                foreach ($mapping as $other => $taken) {
                    if ($taken === $field) {
                        $mapping[$other] = null;
                    }
                }
            }
            $mapping[$index] = $field;
        }

        $plan = ImportPlan::make($import->tenant, $import->headers, $import->cells, $mapping);

        return $this->current->use($import->tenant, function () use ($import, $mapping, $plan): Import {
            $import->forceFill(['mapping' => $mapping, 'rows' => $plan['rows'], 'summary' => $plan['summary']])->save();

            return $import;
        });
    }

    /** What the bytes are: an Excel file is a zip; a CSV is text. */
    private function kind(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if ($head === "PK\x03\x04") {
            return 'xlsx';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/x-csv'], true)) {
            return 'csv';
        }

        throw ValidationException::withMessages(['file' => __('Choose an Excel (.xlsx) or CSV file.')]);
    }
}
