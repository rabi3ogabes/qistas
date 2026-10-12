<?php

/*
| Every public page also answers at its language's own address (/ar/pricing, /fr/pricing, …), so search engines can list
| each language on its own (Sprint 2, decision D10, CM07). The address without a language keeps choosing for the reader
| (?lang=, then what was remembered, then the browser) and is the x-default.
*/

dataset('public pages', [
    'home' => ['/'],
    'calculator' => ['/installment-calculator'],
    'pricing' => ['/pricing'],
    'from a notebook' => ['/switch/notebook'],
    'from Excel' => ['/switch/excel'],
    'from another app' => ['/switch/another-app'],
    'status' => ['/status'],
    'terms' => ['/terms'],
    'privacy' => ['/privacy'],
    'account deletion' => ['/account/delete'],
]);

/** The address of [$path] in [$code]'s language path (the home page is /ar, not /ar/). */
function inLanguage(string $code, string $path): string
{
    return url($code.($path === '/' ? '' : $path));
}

/** The site header's markup, where the page's own links are. */
function siteHeader(string $html): string
{
    preg_match('/<header class="site-header">.*?<\/header>/s', $html, $match);

    return $match[0] ?? '';
}

it('answers each public page in each language path, with its own canonical and every alternate', function (string $path) {
    foreach (config('qistas.locales') as $code) {
        $page = $this->get(inLanguage($code, $path))->assertOk()
            ->assertSee('lang="'.$code.'" dir="'.(in_array($code, ['ar', 'ur'], true) ? 'rtl' : 'ltr').'"', false)
            ->assertHeader('Content-Language', $code)
            ->assertSee('<link rel="canonical" href="'.inLanguage($code, $path).'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url($path).'">', false);

        foreach (config('qistas.locales') as $other) {
            $page->assertSee('<link rel="alternate" hreflang="'.$other.'" href="'.inLanguage($other, $path).'">', false);
        }
    }
})->with('public pages');

it('keeps the address without a language as the reader’s own choice, pointing at the language paths', function () {
    $this->get('/pricing?lang=ar')->assertOk()->assertSee('lang="ar" dir="rtl"', false)
        ->assertSee('<link rel="canonical" href="'.url('/pricing').'">', false)
        ->assertSee('<link rel="alternate" hreflang="fr" href="'.url('/fr/pricing').'">', false)
        ->assertDontSee('?lang=fr', false);
});

it('reads the address without a language in what this browser remembers, else in its own language', function () {
    $this->withHeaders(['Accept-Language' => 'es-MX'])->get('/pricing')->assertSee('<html lang="es"', false);
    // A cookie stays on every later request of a test, so it comes last.
    $this->withCookie('qistas_locale', 'fr')->get('/pricing')->assertSee('<html lang="fr"', false);
});

it('lets the language of the path win over what was remembered, and remembers it in turn', function () {
    $this->withCookie('qistas_locale', 'ar')->get('/fr/pricing')->assertSee('<html lang="fr"', false)->assertCookie('qistas_locale', 'fr');
});

it('moves a ?lang= on a language path to that language’s path, keeping the rest of the address', function () {
    $this->get('/ar/installment-calculator?price=900&lang=fr')->assertRedirect(url('/fr/installment-calculator').'?price=900');
    $this->get('/ar/pricing?lang=klingon')->assertOk()->assertSee('<html lang="ar"', false);
});

it('does not answer for a language the product does not ship, nor for pages that are not public', function () {
    $this->get('/de/pricing')->assertNotFound();
    $this->get('/AR/pricing')->assertNotFound();
    $this->get('/ar/login')->assertNotFound();
    $this->get('/ar/app')->assertNotFound();
});

it('keeps a page’s own links in its language', function () {
    $header = siteHeader($this->get('/ar/pricing')->getContent());

    expect($header)->toContain('href="'.url('/ar').'"')
        ->toContain('href="'.url('/ar/installment-calculator').'"')
        ->toContain('href="'.url('/ar').'#faq"')
        ->not->toContain('href="'.url('/installment-calculator').'"');

    $footer = $this->get('/ar/pricing')->getContent();
    expect($footer)->toContain('href="'.url('/ar/terms').'"')->toContain('href="'.url('/ar/switch/excel').'"');

    expect(siteHeader($this->get('/pricing')->getContent()))->toContain('href="'.url('/installment-calculator').'"');
});

it('switches language on a public page by its language path, keeping the rest of the address', function () {
    $html = $this->get('/pricing?plan=pro')->getContent();

    expect($html)->toContain('hreflang="ar" href="'.url('/ar/pricing').'?plan=pro"')
        ->toContain('hreflang="en" href="'.url('/en/pricing').'?plan=pro"');
    expect($this->get('/fr')->getContent())->toContain('hreflang="ur" href="'.url('/ur').'"');
});

it('keeps ?lang= on the pages that have no language paths', function () {
    expect($this->get('/login')->getContent())->toContain('lang=ar')->not->toContain(url('/ar/login'));
});

it('lists every public page in every language path for search engines, each with its alternates', function () {
    $sitemap = $this->get('/sitemap.xml')->assertOk();

    foreach (['/', '/pricing', '/installment-calculator', '/switch/notebook', '/switch/excel', '/switch/another-app', '/terms', '/privacy'] as $path) {
        $sitemap->assertSee('<loc>'.url($path).'</loc>', false);
        foreach (config('qistas.locales') as $code) {
            $sitemap->assertSee('<loc>'.inLanguage($code, $path).'</loc>', false)
                ->assertSee('<xhtml:link rel="alternate" hreflang="'.$code.'" href="'.inLanguage($code, $path).'"/>', false);
        }
    }
    $sitemap->assertDontSee('?lang=', false)->assertDontSee('/status', false);
});
