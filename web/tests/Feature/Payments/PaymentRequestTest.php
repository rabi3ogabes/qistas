<?php

use App\Http\Requests\PaymentRequest;
use Illuminate\Support\Facades\Validator;

function validatePayment(array $input): Illuminate\Validation\Validator
{
    $request = PaymentRequest::create('/payments', 'POST', $input);
    $request->setContainer(app());
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return Validator::make($request->all(), $request->rules());
}

it('accepts a plain payment and a complete one', function (array $input) {
    expect(validatePayment($input)->passes())->toBeTrue();
})->with([
    'amount and method' => [['amount' => '150', 'method' => 'cash']],
    'everything' => [['amount' => '99.50', 'method' => 'bank_transfer', 'paid_at' => '2026-01-15 10:30:00', 'note' => 'Ref 8841']],
]);

it('rejects bad input, naming the field', function (array $input, string $field) {
    expect(validatePayment($input)->errors()->keys())->toContain($field);
})->with([
    'no amount' => [['method' => 'cash'], 'amount'],
    'zero' => [['amount' => '0', 'method' => 'cash'], 'amount'],
    'negative' => [['amount' => '-5', 'method' => 'cash'], 'amount'],
    'three decimals' => [['amount' => '5.123', 'method' => 'cash'], 'amount'],
    'not a number' => [['amount' => 'ten', 'method' => 'cash'], 'amount'],
    'scientific notation' => [['amount' => '1e2', 'method' => 'cash'], 'amount'],
    'no method' => [['amount' => '10'], 'method'],
    'unknown method' => [['amount' => '10', 'method' => 'bitcoin'], 'method'],
    'future date' => [['amount' => '10', 'method' => 'cash', 'paid_at' => '2999-01-01'], 'paid_at'],
    'garbage date' => [['amount' => '10', 'method' => 'cash', 'paid_at' => 'yesterday-ish'], 'paid_at'],
    'note too long' => [['amount' => '10', 'method' => 'cash', 'note' => str_repeat('n', 1001)], 'note'],
]);

it('reads an amount typed with Arabic-Indic digits', function () {
    $validator = validatePayment(['amount' => '١٥٠٫٥٠', 'method' => 'cash']);

    expect($validator->passes())->toBeTrue()->and($validator->getData()['amount'])->toBe('150.50');
});

it('only offers fields a client may set', function () {
    expect(array_keys((new PaymentRequest)->rules()))->toEqualCanonicalizing(['amount', 'method', 'paid_at', 'note']);
});
