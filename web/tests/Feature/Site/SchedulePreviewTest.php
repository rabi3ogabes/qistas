<?php

use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Models\AuditLog;
use App\Models\Contract;
use Illuminate\Testing\TestResponse;

function preview(array $overrides = []): TestResponse
{
    return test()->postJson('/schedule-preview', array_merge([
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'count' => 4, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01',
    ], $overrides));
}

it('returns the same schedule the server would store, to the cent', function () {
    $expected = (new ScheduleGenerator)->generate(ScheduleRequest::fromArray([
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'count' => 4, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01',
    ]))->toArray();

    // The same schedule, and the discount at sale it was built after (none here).
    preview()->assertOk()->assertExactJson([...$expected, 'discount' => '0.00']);
});

it('needs no account', function () {
    preview()->assertOk();
    $this->assertGuest();
});

it('splits an awkward total so the last instalment absorbs the cent', function () {
    $response = preview(['principal' => '100.00', 'down_payment' => '0', 'markup_type' => 'none', 'markup_value' => '0', 'count' => 3]);

    expect(array_column($response->json('installments'), 'amount'))->toBe(['33.33', '33.33', '33.34']);
});

it('treats a missing first due date as next month', function () {
    $this->travelTo('2026-10-07 10:00:00');

    $response = preview(['first_due_date' => null]);

    $response->assertOk();
    expect($response->json('installments.0.due_date'))->toBe('2026-11-07');
});

it('reads amounts typed on an Arabic keyboard', function () {
    preview(['principal' => '١٢٠٠٫٥٠', 'down_payment' => '٠'])->assertOk()->assertJsonPath('financed', '1200.50');
});

it('refuses impossible plans with a message for the field', function (array $changes, string $field) {
    preview($changes)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no price' => [['principal' => null], 'principal'],
    'zero price' => [['principal' => '0'], 'principal'],
    'negative price' => [['principal' => '-5'], 'principal'],
    'three decimals' => [['principal' => '10.999'], 'principal'],
    'words' => [['principal' => 'a lot'], 'principal'],
    'down payment as high as the price' => [['down_payment' => '1200'], 'down_payment'],
    'too many instalments' => [['count' => 601], 'count'],
    'no instalments' => [['count' => 0], 'count'],
    'unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
    'unknown markup' => [['markup_type' => 'compound'], 'markup_type'],
    'negative markup' => [['markup_value' => '-1'], 'markup_value'],
    'impossible date' => [['first_due_date' => '2026-02-30'], 'first_due_date'],
]);

it('is rate limited per address so it cannot be used to burn the server', function () {
    foreach (range(1, 60) as $_) {
        preview()->assertOk();
    }

    preview()->assertStatus(429);
});

it('never stores anything', function () {
    preview();

    expect(Contract::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});
