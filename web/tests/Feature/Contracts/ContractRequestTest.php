<?php

use App\Http\Requests\ContractRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Validator;

function validateContract(array $input): Illuminate\Validation\Validator
{
    $request = ContractRequest::create('/contracts', 'POST', $input);
    $request->setContainer(app());
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return Validator::make($request->all(), $request->rules());
}

beforeEach(function () {
    $this->tenant = workspaceOn();
    $this->customer = customerIn($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
    $this->valid = [
        'customer_id' => $this->customer->id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '200.00',
        'markup_type' => 'percent', 'markup_value' => '10', 'installment_count' => 4, 'frequency' => 'monthly',
        'start_date' => '2026-01-15', 'first_due_date' => '2026-02-15', 'notes' => 'Washing machine',
    ];
});

it('accepts a full scheduled contract, a minimal one and a cash sale', function () {
    expect(validateContract($this->valid)->passes())->toBeTrue();

    $minimal = ['customer_id' => $this->customer->id, 'principal' => '500', 'installment_count' => 5, 'frequency' => 'weekly', 'first_due_date' => '2026-03-01'];
    expect(validateContract($minimal)->passes())->toBeTrue();

    $cash = ['customer_id' => $this->customer->id, 'type' => 'cash', 'principal' => '99.50'];
    expect(validateContract($cash)->passes())->toBeTrue();
});

it('rejects bad input, naming the field', function (array $changes, string $field) {
    expect(validateContract(array_merge($this->valid, $changes))->errors()->keys())->toContain($field);
})->with([
    'no customer' => [['customer_id' => null], 'customer_id'],
    'unknown type' => [['type' => 'lease'], 'type'],
    'zero principal' => [['principal' => '0'], 'principal'],
    'negative principal' => [['principal' => '-5'], 'principal'],
    'three decimals' => [['principal' => '10.999'], 'principal'],
    'not a number' => [['principal' => 'a lot'], 'principal'],
    'scientific notation' => [['principal' => '1e5'], 'principal'],
    'down payment equals the principal' => [['down_payment' => '1200.00'], 'down_payment'],
    'down payment above the principal' => [['down_payment' => '1500'], 'down_payment'],
    'negative down payment' => [['down_payment' => '-1'], 'down_payment'],
    'unknown markup type' => [['markup_type' => 'compound'], 'markup_type'],
    'negative markup' => [['markup_value' => '-2'], 'markup_value'],
    'no instalments' => [['installment_count' => 0], 'installment_count'],
    'too many instalments' => [['installment_count' => 601], 'installment_count'],
    'fractional count' => [['installment_count' => '2.5'], 'installment_count'],
    'unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
    'impossible date' => [['first_due_date' => '2026-02-30'], 'first_due_date'],
    'wrong date format' => [['first_due_date' => '15/02/2026'], 'first_due_date'],
    'due before the contract date' => [['first_due_date' => '2026-01-01'], 'first_due_date'],
    'notes too long' => [['notes' => str_repeat('n', 5001)], 'notes'],
]);

it('needs a schedule for a scheduled contract but not for a cash sale', function () {
    $noSchedule = ['customer_id' => $this->customer->id, 'principal' => '500'];

    expect(validateContract($noSchedule)->errors()->keys())->toContain('installment_count', 'frequency', 'first_due_date');
    expect(validateContract($noSchedule + ['type' => 'cash'])->passes())->toBeTrue();
});

it('refuses a customer from another workspace or a deleted one', function () {
    $foreign = customerIn(workspaceOn());
    expect(validateContract(array_merge($this->valid, ['customer_id' => $foreign->id]))->errors()->keys())->toContain('customer_id');

    asTenant($this->tenant, fn () => $this->customer->delete());
    expect(validateContract($this->valid)->errors()->keys())->toContain('customer_id');
});

it('refuses every customer when no workspace is active', function () {
    app(CurrentTenant::class)->clear();

    expect(validateContract($this->valid)->errors()->keys())->toContain('customer_id');
});

it('reads amounts and dates typed with Arabic-Indic digits', function () {
    $validator = validateContract(array_merge($this->valid, ['principal' => '١٢٠٠٫٥٠', 'installment_count' => '٤', 'first_due_date' => '٢٠٢٦-٠٢-١٥']));

    expect($validator->passes())->toBeTrue()
        ->and($validator->getData()['principal'])->toBe('1200.50')
        ->and($validator->getData()['first_due_date'])->toBe('2026-02-15');
});

it('only offers fields a client may set', function () {
    expect(array_keys((new ContractRequest)->rules()))->toEqualCanonicalizing([
        'customer_id', 'type', 'principal', 'down_payment', 'markup_type', 'markup_value',
        'installment_count', 'frequency', 'start_date', 'first_due_date', 'notes',
        'grace_days', 'custom_schedule', 'custom_schedule.*.due_date', 'custom_schedule.*.amount', 'investor_id',
    ]);
});
