<?php

use App\Entitlements\Feature;
use App\Import\Ai\AiSettings;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| Reading a notebook photo or another app's PDF report (Win Plan K9, decision D8). Claude reads the page into rows of
| the same fields an Excel file has, and those rows go to the very same trial run: nothing is saved until the owner
| confirms. It is offered only once the owner has saved an Anthropic key, it is part of Pro with a monthly allowance of
| pages, and a photo is drawn again without its hidden data before it leaves for the reader.
*/

beforeEach(fn () => $this->travelTo('2026-10-12 10:00:00'));

/** @return array{0: User, 1: Tenant} an owner on Pro with import, open contracts and AI reading on, and a key saved */
function aiOwner(bool $key = true, bool $pro = true): array
{
    [$user, $tenant] = apiOwner();
    switchOn(Feature::Import);
    switchOn(Feature::OpenContracts);
    switchOn(Feature::AiReading);
    if ($pro) {
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
    }
    if ($key) {
        app(AiSettings::class)->setKey('sk-ant-test-key', null);
    }

    return [$user, $tenant];
}

/** Claude's answer: the rows it read, through the tool it was asked to use. */
function claudeRows(array $rows): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'record_customers', 'input' => ['rows' => $rows]]],
    ];
}

/** A PDF of [$pages] pages. */
function pdfOfPages(int $pages): string
{
    $kids = implode(' ', array_map(fn ($n) => (3 + $n).' 0 R', range(0, $pages - 1)));
    $objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [{$kids}] /Count {$pages} >> endobj\n";
    foreach (range(0, $pages - 1) as $n) {
        $objects .= (3 + $n)." 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\n";
    }

    return "%PDF-1.4\n{$objects}trailer << /Root 1 0 R >>\n%%EOF\n";
}

function aiUsed(Tenant $tenant): int
{
    return (int) DB::table('usage_counters')->where('tenant_id', $tenant->id)->where('feature_key', 'ai_reading')->value('used');
}

it('reads a notebook photo into the same trial run, and counts one page', function () {
    [, $tenant] = aiOwner();
    Http::fake(['api.anthropic.com/*' => Http::response(claudeRows([
        ['name' => 'سالم العتيبي', 'phone' => '0551112233', 'balance' => '850'],
        ['name' => 'نورة', 'phone' => null, 'total' => '1200', 'installments' => '4', 'first_due' => '01/11/2026'],
    ]))]);

    $response = sendBooks(jpegBytes(400, 300, withExif: true), 'notebook.jpg')->assertCreated()
        ->assertJsonPath('data.source', 'ai')->assertJsonPath('data.status', 'trial');
    $rows = rowsOf($response);

    expect($rows[2])->name->toBe('سالم العتيبي')->problems->toBe([])
        ->and($rows[2]['contract'])->type->toBe('open')->balance->toBe('850.00')
        ->and($rows[3]['problems'][0]['field'])->toBe('phone')
        ->and(aiUsed($tenant))->toBe(1);

    Http::assertSent(function (Request $request) {
        $image = base64_decode($request['messages'][0]['content'][0]['source']['data']);

        return $request->hasHeader('x-api-key', 'sk-ant-test-key') && $request['model'] === 'claude-sonnet-5-5'
            && $request['tool_choice']['name'] === 'record_customers'
            && ! str_contains($image, 'GPS:') && ! str_contains($image, 'Exif');
    });
});

it('reads another app’s PDF report, counting its pages', function () {
    [, $tenant] = aiOwner();
    Http::fake(['api.anthropic.com/*' => Http::response(claudeRows([['name' => 'Ahmad Salem', 'phone' => '0501234567']]))]);

    sendBooks(pdfOfPages(3), 'report.pdf')->assertCreated()->assertJsonPath('data.rows.0.name', 'Ahmad Salem');

    expect(aiUsed($tenant))->toBe(3);
    Http::assertSent(fn (Request $request) => $request['messages'][0]['content'][0]['type'] === 'document');
});

it('is not offered without a key, and nothing leaves', function () {
    aiOwner(key: false);
    Http::fake();

    sendBooks(jpegBytes(), 'notebook.jpg')->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');
    $this->getJson('/api/v1/imports/options')->assertOk()->assertJsonPath('data.ai.available', false);

    Http::assertNothingSent();
});

it('is part of Pro: a Free workspace is offered the upgrade, and nothing leaves', function () {
    aiOwner(pro: false);
    Http::fake();

    sendBooks(jpegBytes(), 'notebook.jpg')->assertStatus(402)->assertJsonPath('error.code', 'feature_locked');
    $this->getJson('/api/v1/imports/options')->assertOk()
        ->assertJsonPath('data.ai.available', true)->assertJsonPath('data.ai.included', false);

    Http::assertNothingSent();
});

it('stops at the month’s pages before sending anything', function () {
    [, $tenant] = aiOwner();
    DB::table('usage_counters')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'feature_key' => 'ai_reading', 'period' => '2026-10', 'used' => 49, 'created_at' => now(), 'updated_at' => now()]);
    Http::fake();

    $this->getJson('/api/v1/imports/options')->assertJsonPath('data.ai.pages_left', 1);
    sendBooks(pdfOfPages(2), 'report.pdf')->assertStatus(402)->assertJsonPath('error.code', 'limit_reached');

    Http::assertNothingSent();
    expect(aiUsed($tenant))->toBe(49);
});

it('takes a PDF of at most 20 pages at a time, before sending anything', function () {
    [, $tenant] = aiOwner();
    Http::fake();

    sendBooks(pdfOfPages(21), 'report.pdf')->assertUnprocessable()
        ->assertJsonPath('error.fields.file.0', 'A PDF of at most 20 pages at a time. Split the report.');

    Http::assertNothingSent();
    expect(aiUsed($tenant))->toBe(0);
});

it('says so when no customer is on the page, leaving empty lines out, and counts nothing', function () {
    [, $tenant] = aiOwner();
    $nothing = 'No customers could be read on this page. Try a clearer photo, or an Excel file.';

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(claudeRows([]))
        ->push(claudeRows([['name' => null, 'phone' => '  ', 'notes' => '']]))]);

    sendBooks(jpegBytes(), 'notebook.jpg')->assertUnprocessable()->assertJsonPath('error.fields.file.0', $nothing);
    sendBooks(jpegBytes(), 'notebook.jpg')->assertUnprocessable()->assertJsonPath('error.fields.file.0', $nothing);
    Http::assertSentCount(2);

    expect(aiUsed($tenant))->toBe(0);
});

it('reads only what came through the tool it was asked to use', function () {
    aiOwner();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use',
        'content' => [
            ['type' => 'text', 'text' => 'Here are the customers.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'something_else', 'input' => ['rows' => [['name' => 'Ahmad Salem', 'phone' => '0501234567']]]],
        ],
    ])]);

    sendBooks(jpegBytes(), 'notebook.jpg')->assertUnprocessable()
        ->assertJsonPath('error.fields.file.0', 'The page could not be read just now. Try again in a minute, or bring an Excel file.');
});

it('says so when the page cannot be read, and counts nothing', function () {
    [, $tenant] = aiOwner();
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)]);

    sendBooks(jpegBytes(), 'notebook.jpg')->assertUnprocessable()->assertJsonValidationErrors('file', 'error.fields');

    expect(aiUsed($tenant))->toBe(0);
});

it('keeps the key encrypted, set only by a super admin, and never shows it back', function () {
    $admin = User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $staff = User::factory()->create(['platform_role' => 'admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($staff)->put(route('admin.ai.update'), ['key' => 'sk-ant-new'])->assertForbidden();
    $this->actingAs($admin)->put(route('admin.ai.update'), ['key' => 'sk-ant-new'])->assertRedirect();

    expect((string) PlatformSetting::query()->find(AiSettings::KEY)?->value)->not->toContain('sk-ant-new')
        ->and(app(AiSettings::class)->key())->toBe('sk-ant-new');
    $this->actingAs($admin)->get(route('admin.ai.index'))->assertOk()->assertDontSee('sk-ant-new')->assertSee('A key is saved');
});

it('takes only an Anthropic key, and removes it when asked', function () {
    $admin = User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    app(AiSettings::class)->setKey('sk-ant-old', null);

    $this->actingAs($admin)->put(route('admin.ai.update'), ['key' => 'sk-proj-openai'])->assertSessionHasErrors('key');
    expect(app(AiSettings::class)->key())->toBe('sk-ant-old');

    $this->actingAs($admin)->put(route('admin.ai.update'), ['remove' => '1'])->assertRedirect();
    expect(app(AiSettings::class)->key())->toBeNull();
    $this->actingAs($admin)->get(route('admin.ai.index'))->assertOk()->assertSee('No key yet: photos and PDFs are not offered');
});

it('offers a photo or a PDF on the web page once there is a key, and says when it is part of Pro', function () {
    [$owner, $tenant] = owner();
    switchOn(Feature::Import);
    switchOn(Feature::AiReading);

    $this->actingAs($owner)->get(route('app.import'))->assertOk()->assertDontSee('image/jpeg', false)->assertDontSee('Part of Pro');

    app(AiSettings::class)->setKey('sk-ant-test-key', null);
    $this->actingAs($owner)->get(route('app.import'))->assertOk()->assertSee('Part of Pro')->assertDontSee('image/jpeg', false);

    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
    $this->actingAs($owner)->get(route('app.import'))->assertOk()->assertSee('image/jpeg', false)->assertSee('Pages left this month: 50');
});
