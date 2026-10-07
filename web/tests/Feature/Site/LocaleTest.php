<?php

use App\Models\User;
use App\Support\Locale;

it('uses English when nothing says otherwise', function () {
    $this->get('/login')->assertOk()->assertSee('lang="en" dir="ltr"', false)->assertHeader('Content-Language', 'en');
});

it('switches language with ?lang= and remembers it in a cookie', function () {
    $this->get('/login?lang=ar')
        ->assertOk()
        ->assertSee('lang="ar" dir="rtl"', false)
        ->assertHeader('Content-Language', 'ar')
        ->assertCookie('qistas_locale', 'ar');
});

it('ignores a language the product does not ship', function (string $lang) {
    $this->get("/login?lang={$lang}")->assertOk()->assertSee('lang="en" dir="ltr"', false)->assertCookieMissing('qistas_locale');
})->with(['xx', 'ar-SA', '../../etc/passwd', '<script>', '']);

it('reads the remembered language from the cookie', function () {
    $this->withCookie('qistas_locale', 'fr')->get('/login')->assertSee('lang="fr" dir="ltr"', false);
});

it('ignores a tampered cookie value', function () {
    $this->withCookie('qistas_locale', 'klingon')->get('/login')->assertSee('lang="en" dir="ltr"', false);
});

it('follows the browser’s language preference when nothing is remembered', function (string $header, string $expected) {
    $this->withHeaders(['Accept-Language' => $header])->get('/login')->assertSee("lang=\"{$expected}\"", false);
})->with([
    'arabic first' => ['ar-SA,ar;q=0.9,en;q=0.8', 'ar'],
    'regional french' => ['fr-CH, fr;q=0.9, en;q=0.8', 'fr'],
    'english first' => ['en-US,en;q=0.9,ar;q=0.8', 'en'],
    'urdu' => ['ur-PK,ur;q=0.9', 'ur'],
    'spanish' => ['es-419,es;q=0.9', 'es'],
    'unsupported falls back' => ['de-DE,de;q=0.9,ja;q=0.5', 'en'],
    'later supported language wins over unsupported ones' => ['de,ja;q=0.9,fr;q=0.5', 'fr'],
    'refused languages are skipped' => ['ar;q=0,en;q=0.5', 'en'],
]);

it('lets an explicit choice beat the browser', function () {
    $this->withHeaders(['Accept-Language' => 'fr'])->get('/login?lang=ar')->assertSee('lang="ar"', false);
});

it('uses a signed-in user’s saved language', function () {
    $user = User::factory()->unverified()->create(['locale' => 'ar']);

    $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr'])->get('/email/verify')->assertSee('lang="ar" dir="rtl"', false);
});

it('saves a signed-in user’s explicit choice to their profile', function () {
    $user = User::factory()->unverified()->create(['locale' => 'en']);

    $this->actingAs($user)->get('/email/verify?lang=es');

    expect($user->fresh()->locale)->toBe('es');
});

it('does not touch a visitor’s stored language when they are not signed in', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->get('/login?lang=ar');

    expect($user->fresh()->locale)->toBe('en');
});

it('lays out right-to-left languages from the right', function (string $locale, string $dir) {
    $this->get("/login?lang={$locale}")->assertSee("dir=\"{$dir}\"", false);
})->with([['en', 'ltr'], ['fr', 'ltr'], ['es', 'ltr'], ['ar', 'rtl'], ['ur', 'rtl']]);

it('does not leak one visitor’s language to the next', function () {
    $this->get('/login?lang=ar');
    $this->flushSession();

    $this->get('/login')->assertSee('lang="en"', false);
});

describe('the Locale helper', function () {
    it('names every shipped language in its own script', function () {
        expect(Locale::options())->toBe([
            'en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'es' => 'Español', 'ur' => 'اردو',
        ]);
    });

    it('knows which languages are right-to-left', function () {
        expect(Locale::isRtl('ar'))->toBeTrue()->and(Locale::isRtl('ur'))->toBeTrue()->and(Locale::isRtl('fr'))->toBeFalse();
    });

    it('reports the active language', function () {
        app()->setLocale('ur');

        expect(Locale::current())->toBe('ur')->and(Locale::direction())->toBe('rtl');
    });
});
