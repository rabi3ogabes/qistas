<?php

use App\Support\FieldNames;

/*
| A mistake on a form is told in the person's own language, naming the field the way the form labels it: "the price",
| never "principal" or "customer id". French, Spanish and Urdu have their own messages, not English ones.
*/

function contractMistakes(string $lang): array
{
    [$owner] = owner();

    return test()->actingAs($owner)->post('/app/contracts?lang='.$lang, ['type' => 'scheduled'])
        ->assertSessionHasErrors(['customer_id', 'principal'])
        ->getSession()->get('errors')->getBag('default')->getMessages();
}

it('names fields the way the form labels them', function () {
    $errors = contractMistakes('en');

    expect($errors['customer_id'][0])->toBe('The customer field is required.')
        ->and($errors['principal'][0])->toBe('The price field is required.');
});

it('tells mistakes in French, Spanish and Urdu with their own words', function (string $lang, string $price) {
    $message = contractMistakes($lang)['principal'][0];

    expect($message)->toContain($price)->not->toContain('principal')->not->toContain('field is required');
})->with([
    'French' => ['fr', 'prix'],
    'Spanish' => ['es', 'precio'],
    'Urdu' => ['ur', 'قیمت'],
]);

it('tells mistakes in Arabic with the form’s own field names', function () {
    $errors = contractMistakes('ar');

    expect($errors['principal'][0])->toContain('السعر')->not->toContain('principal')
        ->and($errors['customer_id'][0])->toContain('العميل')->not->toContain('customer');
});

it('keeps a capitalised short name such as VAT as it is', function () {
    app()->setLocale('en');

    expect(FieldNames::all()['vat_number'])->toBe('VAT number');
});

it('has a name for every field a form asks for', function () {
    $missing = array_filter(['customer_id', 'principal', 'installment_count', 'first_due_date', 'start_date', 'down_payment',
        'frequency', 'markup_value', 'grace_days', 'amount', 'method', 'paid_at', 'phone', 'phone_secondary', 'national_id',
        'current_password', 'password', 'locale', 'photo'], fn (string $key) => ! array_key_exists($key, FieldNames::all()));

    expect($missing)->toBe([]);
});
