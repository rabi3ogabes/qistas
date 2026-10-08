<?php

it('uses our own wording where the framework translations also have a sentence', function (string $locale, string $sentence, string $expected) {
    app()->setLocale($locale);

    expect(__($sentence))->toBe($expected);
})->with([
    'Spanish asks for a password in the second person' => ['es', 'Reset your password', 'Restablece tu contraseña'],
    'French Edit' => ['fr', 'Edit', 'Modifier'],
    'Urdu Cancel is an action' => ['ur', 'Cancel', 'منسوخ کریں'],
    'Arabic Continue' => ['ar', 'Continue', 'متابعة'],
]);

it('still falls back to the framework wording for sentences we do not translate', function () {
    app()->setLocale('es');

    expect(__('Whoops!'))->not->toBe('Whoops!');
});

it('shows English as written', function () {
    app()->setLocale('en');

    expect(__('Admin area'))->toBe('Admin area')->and(__('Start again'))->toBe('Start again');
});
