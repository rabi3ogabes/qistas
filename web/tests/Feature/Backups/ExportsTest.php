<?php

use App\Actions\RecordPayment;
use App\Exports\ExportService;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use OpenSpout\Reader\XLSX\Reader;

/*
| Win Plan PP10: a business can see when its books were last copied and take everything with it in Excel, whatever its
| plan, even while it is being deleted. Every night each business gets its own encrypted copy, seven kept. A download
| is a link that works for five minutes. Collectors cannot take the books.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

/**
 * A business with two customers, a contract with a payment, and another business next door with its own customer.
 *
 * @return array{0: User, 1: Tenant}
 */
function booksToExport(): array
{
    [$owner, $tenant] = apiOwner();
    customerIn($tenant, ['name' => 'سارة القحطاني']);
    $contract = openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Ahmad Salem'])->id]);
    app(RecordPayment::class)->handle($contract, '150.00', 'cash', by: $owner, paidAt: Carbon::parse('2026-02-05 10:00:00'));

    [, $neighbour] = owner();
    customerIn($neighbour, ['name' => 'Someone Else Entirely']);

    return [$owner, $tenant];
}

/**
 * Every sheet of an Excel file, by name, as rows of cell values.
 *
 * @return array<string, list<list<mixed>>>
 */
function workbookSheets(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $bytes);
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    return $sheets;
}

/** The file behind an export, fetched the way a person's browser or phone does: through the five-minute link. */
function exportFile(WorkspaceExport $export): string
{
    $url = test()->getJson("/api/v1/exports/{$export->id}/download")->assertOk()->json('data.url');

    return (string) test()->get($url)->assertOk()->getContent();
}

function newExport(array $body = []): WorkspaceExport
{
    $id = test()->postJson('/api/v1/exports', $body)->assertStatus(202)->json('data.id');

    return WorkspaceExport::withoutGlobalScopes()->findOrFail($id);
}

describe('download everything', function () {
    it('puts every row of the business in one Excel file, and nothing of another', function () {
        booksToExport();

        $sheets = workbookSheets(exportFile(newExport()));

        expect(array_keys($sheets))->toBe(['Customers', 'Contracts', 'Instalments', 'Payments', 'What was sold', 'Investors', 'Investor entries'])
            ->and(count($sheets['Customers']))->toBe(3)
            ->and(collect($sheets['Customers'])->flatten()->all())->toContain('سارة القحطاني', 'Ahmad Salem')->not->toContain('Someone Else Entirely')
            ->and(count($sheets['Contracts']))->toBe(2)
            ->and(count($sheets['Instalments']))->toBe(4)
            ->and(count($sheets['Payments']))->toBe(2)
            ->and(collect($sheets['Payments'])->flatten()->all())->toContain('C-0001')
            ->and(collect($sheets['Payments'])->flatten()->contains(fn (mixed $cell) => is_numeric($cell) && (float) $cell === 150.0))->toBeTrue();
    });

    it('counts the rows of each sheet', function () {
        booksToExport();

        $this->postJson('/api/v1/exports')->assertStatus(202)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.row_counts.customers', 2)
            ->assertJsonPath('data.row_counts.payments', 1);
    });

    it('also comes as CSV files that open in Excel with Arabic intact', function () {
        booksToExport();

        $zip = exportFile(newExport(['format' => 'csv']));
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $zip);
        $archive = new ZipArchive;
        $archive->open($path);
        $customers = (string) $archive->getFromName('customers.csv');
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = $archive->getNameIndex($i);
        }
        $archive->close();
        unlink($path);

        expect($names)->toContain('customers.csv', 'contracts.csv', 'instalments.csv', 'payments.csv')
            ->and(substr($customers, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and($customers)->toContain('سارة القحطاني');
    });

    it('works on the free plan, after a paid plan has lapsed, and while the business is being deleted', function () {
        [$owner, $tenant] = booksToExport();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->subMonths(2));
        $this->postJson('/api/v1/exports')->assertStatus(202);

        // The owner asked to delete the business: everything is read-only for 30 days, except taking the books.
        $tenant->forceFill(['deletion_requested_at' => now(), 'delete_after' => now()->addDays(30)])->save();
        $this->postJson('/api/v1/customers', ['name' => 'Late addition', 'phone' => '+966500000000'])->assertStatus(423);
        $export = newExport();

        expect(workbookSheets(exportFile($export)))->toHaveKey('Customers');
    });

    it('is kept encrypted, never as readable data', function () {
        booksToExport();

        $export = newExport();

        $raw = (string) $export->getRawOriginal('payload');

        // Laravel's encryption with the app key around compressed bytes: no name, no zip to be seen.
        expect($raw)->not->toContain('Ahmad Salem')->and(str_starts_with($raw, 'PK'))->toBeFalse()
            ->and(base64_decode(Crypt::decryptString($raw), true))->not->toBeFalse();
    });

    it('is not for collectors or viewers', function (string $role) {
        [, $tenant] = booksToExport();
        apiMember($role, $tenant);

        $this->getJson('/api/v1/backups')->assertForbidden();
        $this->postJson('/api/v1/exports')->assertForbidden();
    })->with(['collector', 'viewer']);

    it('is never another business’s', function () {
        booksToExport();
        $export = newExport();
        apiOwner();

        $this->getJson("/api/v1/exports/{$export->id}")->assertNotFound();
        $this->getJson("/api/v1/exports/{$export->id}/download")->assertNotFound();
    });

    it('is written to the activity log', function () {
        [$owner, $tenant] = booksToExport();

        newExport();

        expect(AuditLog::where('tenant_id', $tenant->id)->where('action', 'export.created')->where('user_id', $owner->id)->exists())->toBeTrue();
    });
});

describe('the download link', function () {
    it('works for five minutes', function () {
        booksToExport();
        $export = newExport();
        $url = $this->getJson("/api/v1/exports/{$export->id}/download")->assertOk()->json('data.url');

        $this->travel(4)->minutes();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('cannot be made up', function () {
        booksToExport();
        $export = newExport();

        $this->get("/exports/{$export->id}/file")->assertForbidden();
    });
});

describe('the nightly copies', function () {
    it('makes one copy a night for each business and keeps exactly seven', function () {
        [, $tenant] = booksToExport();

        foreach (range(1, 9) as $night) {
            $this->travelTo(Carbon::parse('2026-10-11 02:00:00')->addDays($night));
            $this->artisan('qistas:export-workspaces')->assertSuccessful();
            $this->artisan('qistas:export-workspaces')->assertSuccessful();
        }

        $copies = WorkspaceExport::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', 'nightly')->orderBy('created_at')->get();
        expect($copies)->toHaveCount(7)
            ->and($copies->first()->created_at->toDateString())->toBe('2026-10-14')
            ->and($copies->last()->created_at->toDateString())->toBe('2026-10-20');
    });

    it('leaves out demo accounts, and works through the businesses a few at a time', function () {
        booksToExport();
        owner();
        $demo = Tenant::factory()->create(['is_demo' => true]);

        $this->artisan('qistas:export-workspaces', ['--limit' => 1])->assertSuccessful();
        expect(WorkspaceExport::withoutGlobalScopes()->where('kind', 'nightly')->count())->toBe(1);

        $this->artisan('qistas:export-workspaces', ['--limit' => 10])->assertSuccessful();
        expect(WorkspaceExport::withoutGlobalScopes()->where('kind', 'nightly')->count())->toBe(3)
            ->and(WorkspaceExport::withoutGlobalScopes()->where('tenant_id', $demo->id)->exists())->toBeFalse();
    });

    it('lets go of a download after a day', function () {
        booksToExport();
        newExport();

        $this->travel(25)->hours();
        $this->artisan('qistas:export-workspaces')->assertSuccessful();

        expect(WorkspaceExport::withoutGlobalScopes()->where('kind', 'manual')->count())->toBe(0);
    });

    it('shows when the books were last copied, and the copies', function () {
        [, $tenant] = booksToExport();
        $this->travelTo('2026-10-12 02:00:00');
        app(ExportService::class)->nightly($tenant);
        $this->travelTo('2026-10-12 09:00:00');

        $this->getJson('/api/v1/backups')->assertOk()
            ->assertJsonPath('data.last_backup_at', fn (string $at) => str_starts_with($at, '2026-10-12T02:00'))
            ->assertJsonPath('data.copies.0.kind', 'nightly')
            ->assertJsonPath('meta.can_export', true);
    });
});

describe('on the web', function () {
    it('has a Backups & data page that downloads everything at once', function () {
        [$owner, $tenant] = booksToExport();

        $this->actingAs($owner)->get(route('app.settings.backups'))->assertOk()->assertSee('Download everything');
        $response = $this->actingAs($owner)->post(route('app.exports.store'), ['format' => 'xlsx'])->assertRedirect();
        $this->actingAs($owner)->get($response->headers->get('Location'))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qistas-'.today()->format('Y-m-d').'.xlsx"');
    });

    it('is not offered to a collector', function () {
        [, $tenant] = booksToExport();
        $collector = memberAs('collector', $tenant);

        $this->actingAs($collector)->get(route('app.settings.backups'))->assertForbidden();
        $this->actingAs($collector)->get('/app')->assertDontSee(route('app.settings.backups'), false);
    });
});
