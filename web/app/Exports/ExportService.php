<?php

namespace App\Exports;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceExport;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * A business's books as one file (Win Plan PP10). Every night each business gets its own copy (seven are kept); anyone
 * who may take the books can ask for one at any time, on any plan, even while the business is being deleted (kept a
 * day). The file is compressed and encrypted with the app key before it is stored, and only ever leaves through a link
 * that works for five minutes.
 */
final class ExportService
{
    public const NIGHTLY_KEPT = 7;

    /** A copy someone asked for is kept this long. */
    public const DOWNLOAD_HOURS = 24;

    public const LINK_MINUTES = 5;

    /** A business whose last nightly copy is older than this is due another. */
    private const NIGHTLY_EVERY_HOURS = 20;

    public function manual(Tenant $tenant, string $format, User $by): WorkspaceExport
    {
        $export = $this->build($tenant, 'manual', $format, App::getLocale(), $by, now()->addHours(self::DOWNLOAD_HOURS));
        Audit::record('export.created', $export, ['format' => $format], $tenant->id, $by->id);

        return $export;
    }

    /** Tonight's copy for [$tenant], in its owner's language, then only the newest seven are kept. */
    public function nightly(Tenant $tenant): WorkspaceExport
    {
        $language = (string) ($tenant->owner?->locale ?: config('app.locale'));
        $export = $this->build($tenant, 'nightly', 'xlsx', in_array($language, (array) config('qistas.locales'), true) ? $language : 'en', null, null);
        $this->keepNewestNightly($tenant);

        return $export;
    }

    /**
     * Up to [$limit] real businesses whose last nightly copy is old enough (or who have none), the longest waiting first.
     * Demo accounts and admins' test workspaces get none.
     *
     * @return Collection<int, Tenant>
     */
    public function due(int $limit): Collection
    {
        return Tenant::query()->with('owner')
            ->where('is_demo', false)->where('is_test', false)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('workspace_exports')
                ->whereColumn('workspace_exports.tenant_id', 'tenants.id')
                ->where('workspace_exports.kind', 'nightly')
                ->where('workspace_exports.created_at', '>', now()->subHours(self::NIGHTLY_EVERY_HOURS)))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /** One run of the nightly work: copies for the businesses that are due, and downloads past their day let go. */
    public function runNightly(int $limit): int
    {
        WorkspaceExport::withoutGlobalScope(TenantScope::class)->where('kind', 'manual')->where('expires_at', '<', now())->delete();

        $made = 0;
        foreach ($this->due($limit) as $tenant) {
            try {
                $this->nightly($tenant);
                $made++;
            } catch (Throwable $e) {
                // One business's copy failing must not stop the others'.
                report($e);
            }
        }

        return $made;
    }

    /** The file itself, decrypted. */
    public function bytes(WorkspaceExport $export): string
    {
        return (string) gzdecode(base64_decode(Crypt::decryptString((string) $export->payload), true) ?: '');
    }

    /** @return array{url: string, expires_at: CarbonInterface} a link to the file that works for five minutes */
    public function link(WorkspaceExport $export): array
    {
        $expires = now()->addMinutes(self::LINK_MINUTES);

        return ['url' => URL::temporarySignedRoute('exports.file', $expires, ['export' => $export->id]), 'expires_at' => $expires];
    }

    /**
     * The export a signed link points at, from any workspace: the signature, checked before this is called, is what
     * allows it. The only place an export is read outside its workspace.
     */
    public static function findForLink(string $id): ?WorkspaceExport
    {
        return WorkspaceExport::withoutGlobalScope(TenantScope::class)->where('status', 'ready')->find($id);
    }

    private function build(Tenant $tenant, string $kind, string $format, string $language, ?User $by, ?CarbonInterface $expires): WorkspaceExport
    {
        return app(CurrentTenant::class)->use($tenant, function () use ($tenant, $kind, $format, $language, $by, $expires): WorkspaceExport {
            $previous = App::getLocale();
            App::setLocale($language);

            try {
                $sheets = WorkspaceData::sheets();
                [$bytes, $counts] = $format === 'csv'
                    ? Workbook::csvZip($sheets)
                    : Workbook::xlsx($sheets, in_array($language, (array) config('qistas.rtl_locales'), true));
            } finally {
                App::setLocale($previous);
            }

            $export = (new WorkspaceExport)->forceFill([
                'tenant_id' => $tenant->id,
                'kind' => $kind,
                'format' => $format,
                'status' => 'ready',
                'payload' => Crypt::encryptString(base64_encode((string) gzencode($bytes, 6))),
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'row_counts' => $counts,
                'language' => $language,
                'requested_by_user_id' => $by?->getKey(),
                'expires_at' => $expires,
            ]);
            $export->save();

            return $export;
        });
    }

    private function keepNewestNightly(Tenant $tenant): void
    {
        app(CurrentTenant::class)->use($tenant, function (): void {
            $keep = WorkspaceExport::query()->where('kind', 'nightly')->latest('created_at')->orderByDesc('id')->limit(self::NIGHTLY_KEPT)->pluck('id');
            WorkspaceExport::query()->where('kind', 'nightly')->whereNotIn('id', $keep)->delete();
        });
    }
}
