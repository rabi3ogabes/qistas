<?php

use App\Models\Customer;

function contact(array $attributes): Customer
{
    return (new Customer)->forceFill($attributes);
}

it('masks the national ID down to its last three digits', function (?string $id, ?string $expected) {
    expect(contact(['national_id' => $id])->maskedNationalId())->toBe($expected);
})->with([
    'long' => ['1098765432', '••••••432'],
    'short' => ['12345', '••345'],
    'tiny' => ['12', '••'],
    'none' => [null, null],
    'blank' => ['', null],
]);

it('builds a call link that keeps only dialable characters', function (string $phone, string $expected) {
    expect(contact(['phone' => $phone])->telUrl())->toBe($expected);
})->with([
    'spaced international' => ['+966 50 123 4567', 'tel:+966501234567'],
    'dashes and brackets' => ['(050) 123-4567', 'tel:0501234567'],
    'plain' => ['0501234567', 'tel:0501234567'],
]);

it('builds a WhatsApp link only when the number is international', function (string $phone, ?string $expected) {
    expect(contact(['phone' => $phone])->whatsappUrl())->toBe($expected);
})->with([
    'plus prefix' => ['+966 50 123 4567', 'https://wa.me/966501234567'],
    'double-zero prefix' => ['00971 55 987 6543', 'https://wa.me/971559876543'],
    'local number' => ['0501234567', null],
]);
