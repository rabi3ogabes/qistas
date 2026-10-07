<?php

it('declares the qistas configuration', function () {
    expect(config('qistas.app_name'))->toBe('Qistas')
        ->and(config('qistas.locales'))->toBe(['en', 'ar', 'fr', 'es', 'ur'])
        ->and(config('qistas.rtl_locales'))->toBe(['ar', 'ur'])
        ->and(config('qistas.billing.gateway'))->toBeIn(['fake', 'stripe']);
});

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});
