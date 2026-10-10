<?php

use App\Http\Requests\CustomerRequest;
use Illuminate\Support\Facades\Validator;

/** What the form request would accept, after it has tidied the input the way it does for real requests. */
function validateCustomer(array $input): Illuminate\Validation\Validator
{
    $request = CustomerRequest::create('/customers', 'POST', $input);
    $request->setContainer(app());
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return Validator::make($request->all(), $request->rules());
}

it('accepts a minimal customer and a complete one', function (array $input) {
    expect(validateCustomer($input)->passes())->toBeTrue();
})->with([
    'name and phone only' => [['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567']],
    'everything' => [[
        'name' => 'ليلى الفارس', 'phone' => '050-123-4567', 'phone_secondary' => '+971 55 987 6543',
        'email' => 'layla@example.com', 'national_id' => '1098765432', 'address' => 'Riyadh, Al Olaya', 'notes' => 'Prefers WhatsApp',
    ]],
]);

it('rejects bad input, naming the field', function (array $input, string $field) {
    expect(validateCustomer($input)->errors()->keys())->toContain($field);
})->with([
    'no name' => [['phone' => '+966501234567'], 'name'],
    'blank name' => [['name' => '   ', 'phone' => '+966501234567'], 'name'],
    'name too long' => [['name' => str_repeat('a', 256), 'phone' => '+966501234567'], 'name'],
    'no phone' => [['name' => 'Layla'], 'phone'],
    'phone with letters' => [['name' => 'Layla', 'phone' => 'call me'], 'phone'],
    'phone too short' => [['name' => 'Layla', 'phone' => '123'], 'phone'],
    'secondary phone with letters' => [['name' => 'Layla', 'phone' => '+966501234567', 'phone_secondary' => 'abc'], 'phone_secondary'],
    'bad email' => [['name' => 'Layla', 'phone' => '+966501234567', 'email' => 'nope'], 'email'],
    'national id too long' => [['name' => 'Layla', 'phone' => '+966501234567', 'national_id' => str_repeat('1', 41)], 'national_id'],
    'notes too long' => [['name' => 'Layla', 'phone' => '+966501234567', 'notes' => str_repeat('n', 5001)], 'notes'],
]);

it('reads phone numbers and IDs typed with Arabic-Indic digits', function () {
    $validator = validateCustomer(['name' => 'Layla', 'phone' => '٠٥٠ ١٢٣ ٤٥٦٧', 'national_id' => '١٠٩٨٧٦٥٤٣٢']);

    expect($validator->passes())->toBeTrue()
        ->and($validator->getData()['phone'])->toBe('050 123 4567')
        ->and($validator->getData()['national_id'])->toBe('1098765432');
});

it('trims whitespace and turns empty optional fields into nothing', function () {
    $data = validateCustomer(['name' => '  Layla  ', 'phone' => ' 0501234567 ', 'email' => '', 'notes' => ''])->getData();

    expect($data['name'])->toBe('Layla')->and($data['phone'])->toBe('0501234567')
        ->and($data['email'])->toBeNull()->and($data['notes'])->toBeNull();
});

it('only ever offers fields the customer table lets a client set', function () {
    $fields = array_keys((new CustomerRequest)->rules());

    expect($fields)->toEqualCanonicalizing(['name', 'phone', 'phone_secondary', 'email', 'national_id', 'address', 'notes', 'remove_national_id', 'job']);
});
