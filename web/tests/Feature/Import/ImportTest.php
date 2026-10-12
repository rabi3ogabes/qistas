<?php

use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;

/*
| Bringing a shop's books in (Win Plan K9, RP17, decision D8): an Excel or CSV file whose columns are recognised in five
| languages, a trial run that shows every row and every problem (by row and column) and saves nothing, then one confirm.
| Old balances become opening lines: a contract with a plan gets what was already paid as a payment dated on the import
| day; a balance with no plan opens an open contract with it as the opening balance.
*/

beforeEach(fn () => $this->travelTo('2026-10-12 10:00:00'));

/** @return array{0: User, 1: Tenant} an owner on the API, with import (and open contracts) switched on */
function importOwner(bool $openContracts = true): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Import);
    if ($openContracts) {
        switchOn(Feature::OpenContracts);
    }

    return [$user, $tenant];
}

const IMPORT_HEADERS = ['الاسم', 'رقم الجوال', 'Total', 'المدفوع', 'عدد الأقساط', 'تاريخ أول قسط', 'المتبقي', 'السلعة'];

describe('reading the file', function () {
    it('recognises Arabic and English column names', function () {
        importOwner();

        $response = sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad Salem', '0501234567', 1200, 300, 4, '2026-11-01', null, 'Galaxy S25']]))->assertCreated();

        expect(collect($response->json('data.columns'))->pluck('field', 'header')->all())->toBe([
            'الاسم' => 'name', 'رقم الجوال' => 'phone', 'Total' => 'total', 'المدفوع' => 'paid',
            'عدد الأقساط' => 'installments', 'تاريخ أول قسط' => 'first_due', 'المتبقي' => 'balance', 'السلعة' => 'item',
        ]);
    });

    it('reads a CSV with semicolons, a byte-order mark and Arabic digits', function () {
        importOwner();
        $csv = "\u{FEFF}Nom;Téléphone;Solde\nAhmad Salem;٠٥٠١٢٣٤٥٦٧;١٢٠٠\n";

        $row = rowsOf(sendBooks($csv, 'clients.csv')->assertCreated())[2];

        expect($row)->name->toBe('Ahmad Salem')->phone->toBe('0501234567')->problems->toBe([])
            ->and($row['contract'])->type->toBe('open')->balance->toBe('1200.00');
    });

    it('refuses a file that is not a spreadsheet, and an empty one', function () {
        importOwner();

        sendBooks(pdfBytes(), 'books.xlsx')->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
        sendBooks(xlsxOf([IMPORT_HEADERS]))->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
    });
});

describe('the trial run', function () {
    it('saves nothing and names every problem by row and column', function () {
        [, $tenant] = importOwner();

        $response = sendBooks(xlsxOf([
            IMPORT_HEADERS,
            ['Ahmad Salem', '0501234567', 1200, 300, 4, '2026-11-01', null, null],
            [null, '0502222222', null, null, null, null, null, null],
            ['Saad', 'call me', null, null, null, null, null, null],
            ['Fahad', '0503333333', 'one thousand', null, 4, 'next month', null, null],
            ['Majed', '0504444444', 1000, 1500, 4, '2026-11-01', null, null],
        ]))->assertCreated();
        $rows = rowsOf($response);

        expect($rows[2]['problems'])->toBe([])
            ->and($rows[3]['problems'])->toBe([['column' => 'الاسم', 'field' => 'name', 'message' => 'Add the customer’s name.']])
            ->and($rows[4]['problems'])->toBe([['column' => 'رقم الجوال', 'field' => 'phone', 'message' => 'This is not a phone number.']])
            ->and(collect($rows[5]['problems'])->pluck('column')->all())->toBe(['Total', 'تاريخ أول قسط'])
            ->and($rows[5]['problems'][0]['message'])->toBe('Write the amount in figures, such as 1200.')
            ->and($rows[6]['problems'][0]['message'])->toBe('What was paid is more than the total.')
            ->and($response->json('data.summary'))->toMatchArray(['rows' => 5, 'ready' => 1, 'problems' => 4])
            ->and($response->json('data.status'))->toBe('trial');

        expect(asTenant($tenant, fn () => [Customer::query()->count(), Contract::query()->count()]))->toBe([0, 0]);
    });

    it('flags a phone already on the books, and the same phone twice in the file', function () {
        [, $tenant] = importOwner();
        $existing = customerIn($tenant, ['name' => 'Ahmad Salem', 'phone' => '0501234567']);

        $rows = rowsOf(sendBooks(xlsxOf([
            IMPORT_HEADERS,
            ['Ahmad S.', '+966501234567', 1200, 0, 4, '2026-11-01', null, null],
            ['Khalid', '0507777777', null, null, null, null, null, null],
            ['Khalid again', '050 777 7777', null, null, null, null, null, null],
        ]))->assertCreated());

        expect($rows[2])->action->toBe('existing')->problems->toBe([])
            ->and($rows[2]['customer_id'])->toBe($existing->id)
            ->and($rows[3]['action'])->toBe('new')
            ->and($rows[4]['problems'])->toBe([['column' => 'رقم الجوال', 'field' => 'phone', 'message' => 'Same phone as row 3.']]);
    });

    it('needs open contracts for a balance with no plan, and says so', function () {
        importOwner(openContracts: false);

        $row = rowsOf(sendBooks(xlsxOf([['Name', 'Phone', 'Balance'], ['Ahmad Salem', '0501234567', 500]]))->assertCreated())[2];

        expect($row['problems'][0])->toMatchArray(['column' => 'Balance', 'field' => 'balance'])
            ->and($row['problems'][0]['message'])->toBe('This balance has no instalments. Add the number of instalments and the next due date.');
    });

    it('lets the owner choose a column the file named its own way', function () {
        importOwner();
        $id = sendBooks(xlsxOf([['Customer name', 'Mobile no.', 'Owes us'], ['Ahmad Salem', '0501234567', 500]]))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/imports/{$id}", ['mapping' => [2 => 'balance']])->assertOk()
            ->assertJsonPath('data.columns.2.field', 'balance')
            ->assertJsonPath('data.rows.0.contract.balance', '500.00');
    });
});

describe('confirming', function () {
    it('turns old balances into opening lines', function () {
        [$user, $tenant] = importOwner();
        $id = sendBooks(xlsxOf([
            IMPORT_HEADERS,
            ['Ahmad Salem', '0501234567', 1200, 300, 4, '2026-09-01', null, 'Galaxy S25'],
            ['Saad Omar', '0502222222', null, null, null, null, 500, null],
            ['Fahad Ali', '0503333333', null, null, null, null, null, null],
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/imports/{$id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'done')->assertJsonPath('data.summary.customers', 3)->assertJsonPath('data.summary.contracts', 2);

        asTenant($tenant, function () use ($user) {
            $scheduled = Contract::query()->where('type', 'scheduled')->sole();
            $paid = Transaction::query()->where('contract_id', $scheduled->id)->where('type', 'payment')->sole();
            expect($scheduled)->total->toBe('1200.0000')->installment_count->toBe(4)
                ->and($scheduled->first_due_date->format('Y-m-d'))->toBe('2026-09-01')
                ->and($scheduled->customer->name)->toBe('Ahmad Salem')
                ->and($paid)->amount->toBe('300.0000')->note->toBe('Paid before Qistas')->created_by_user_id->toBe($user->id)
                ->and($paid->paid_at->format('Y-m-d'))->toBe('2026-10-12');

            $open = Contract::query()->where('type', 'open')->sole();
            $opening = Transaction::query()->where('contract_id', $open->id)->sole();
            expect($opening)->type->toBe('charge')->amount->toBe('500.0000')
                ->and(Customer::query()->where('name', 'Fahad Ali')->sole()->contracts()->count())->toBe(0);
        });
    });

    it('imports only the rows without a problem, and can be confirmed once', function () {
        [, $tenant] = importOwner();
        $id = sendBooks(xlsxOf([
            IMPORT_HEADERS,
            ['Ahmad Salem', '0501234567', null, null, null, null, null, null],
            [null, '0502222222', null, null, null, null, null, null],
        ]))->assertCreated()->json('data.id');

        $first = $this->postJson("/api/v1/imports/{$id}/confirm")->assertOk()->json('data.summary');
        $again = $this->postJson("/api/v1/imports/{$id}/confirm")->assertOk()->json('data.summary');

        expect($again)->toBe($first)->and($first)->toMatchArray(['customers' => 1, 'skipped' => 1])
            ->and(asTenant($tenant, fn () => Customer::query()->count()))->toBe(1);
    });

    it('keeps what was read encrypted, never shows an ID number whole, and drops the cells once done', function () {
        importOwner();
        $id = sendBooks(xlsxOf([['Name', 'Phone', 'ID number'], ['Ahmad Salem', '0501234567', '1098765432']]))->assertCreated()
            ->assertJsonPath('data.rows.0.national_id', '••••••432')->json('data.id');

        $stored = DB::table('imports')->where('id', $id)->first();
        expect($stored->cells.$stored->rows)->not->toContain('1098765432')->not->toContain('0501234567');

        $this->postJson("/api/v1/imports/{$id}/confirm")->assertOk();
        expect(Import::withoutGlobalScopes()->findOrFail($id)->cells)->toBe([])
            ->and(json_encode(Import::withoutGlobalScopes()->findOrFail($id)->rows))->not->toContain('1098765432');
    });

    it('adds a contract to a customer already on the books instead of a second customer', function () {
        [, $tenant] = importOwner();
        customerIn($tenant, ['name' => 'Ahmad Salem', 'phone' => '0501234567']);
        $id = sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad S.', '0501234567', null, null, null, null, 700, null]]))->json('data.id');

        $this->postJson("/api/v1/imports/{$id}/confirm")->assertOk();

        expect(asTenant($tenant, fn () => [Customer::query()->count(), Customer::query()->sole()->contracts()->count()]))->toBe([1, 1]);
    });
});

describe('plan limits', function () {
    it('keeps the customers a Free plan allows, and marks the rest', function () {
        [, $tenant] = importOwner();
        foreach (range(1, Feature::FREE_CUSTOMERS - 2) as $n) {
            customerIn($tenant, ['phone' => '05000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
        }

        $response = sendBooks(xlsxOf([['Name', 'Phone'], ['A', '0511111111'], ['B', '0522222222'], ['C', '0533333333'], ['D', '0544444444']]))->assertCreated();
        $rows = rowsOf($response);

        expect($rows[3]['problems'])->toBe([])
            ->and($rows[4]['problems'][0]['message'])->toBe('Your plan keeps 20 customers. Upgrade to bring the rest.')
            ->and($rows[5]['problems'])->not->toBe([]);

        $this->postJson("/api/v1/imports/{$response->json('data.id')}/confirm")->assertOk();
        expect(asTenant($tenant, fn () => Customer::query()->count()))->toBe(Feature::FREE_CUSTOMERS);
    });

    it('takes at most 100 rows at a time on Free', function () {
        importOwner();
        $rows = [['Name', 'Phone']];
        foreach (range(1, 101) as $n) {
            $rows[] = ['Customer '.$n, '055'.str_pad((string) $n, 7, '0', STR_PAD_LEFT)];
        }

        sendBooks(xlsxOf($rows))->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
    });
});

describe('who may', function () {
    it('is for owners and managers, with the switch on', function () {
        [$user, $tenant] = importOwner();

        apiMember('accountant', $tenant);
        sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad', '0501234567']]))->assertForbidden();

        apiMember('manager', $tenant);
        sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad', '0501234567']]))->assertCreated();

        switchOff(Feature::Import);
        Sanctum::actingAs($user, ['app']);
        sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad', '0501234567']]))->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('says not found for another workspace’s import', function () {
        importOwner();
        $id = sendBooks(xlsxOf([IMPORT_HEADERS, ['Ahmad', '0501234567']]))->json('data.id');

        importOwner();
        $this->getJson("/api/v1/imports/{$id}")->assertNotFound();
        $this->postJson("/api/v1/imports/{$id}/confirm")->assertNotFound();
        expect(Import::withoutGlobalScopes()->count())->toBe(1);
    });
});

describe('on the web', function () {
    it('takes a file, shows the trial run with its problems, and imports on confirm', function () {
        [$owner, $tenant] = owner();
        switchOn(Feature::Import);

        $this->actingAs($owner)->get(route('app.import'))->assertOk()->assertSee('name="file"', false)->assertSee(route('app.import.template'), false);
        $response = $this->actingAs($owner)->post(route('app.import.store'), ['file' => upload(xlsxOf([IMPORT_HEADERS, ['Ahmad Salem', '0501234567'], [null, '0502222222']]), 'books.xlsx')]);
        $import = asTenant($tenant, fn () => Import::query()->sole());
        $response->assertRedirect(route('app.import.show', $import));

        $this->actingAs($owner)->get(route('app.import.show', $import))->assertOk()
            ->assertSee('Ahmad Salem')->assertSee('Add the customer’s name.')->assertSee(route('app.import.confirm', $import), false);
        $this->actingAs($owner)->post(route('app.import.confirm', $import))->assertRedirect(route('app.import.show', $import));

        expect(asTenant($tenant, fn () => Customer::query()->pluck('name')->all()))->toBe(['Ahmad Salem']);
    });

    it('hands out a template with the column names in the reader’s language', function () {
        [$owner] = owner();
        switchOn(Feature::Import);

        $response = $this->actingAs($owner)->get(route('app.import.template', ['language' => 'ar']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, (string) $response->getContent());
        $reader = new Reader;
        $reader->open($path);
        $headers = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $headers = $row->toArray();
                break 2;
            }
        }
        $reader->close();
        @unlink($path);

        expect($headers)->toContain('الاسم')->toContain('رقم الجوال');
    });
});
