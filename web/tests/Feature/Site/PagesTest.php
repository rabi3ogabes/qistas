<?php

use App\Entitlements\Feature;
use App\Models\Plan;

describe('the home page', function () {
    it('opens with a clear offer and a way to start', function () {
        $page = $this->get('/');

        $page->assertOk()
            ->assertSee('Every instalment, to the cent.')
            ->assertSee(route('register'), false)
            ->assertSee(route('login'), false)
            ->assertSee(url('/pricing'), false);
    });

    it('states the free allowance from the admin’s plan settings, not from copy', function () {
        $this->get('/')->assertSee('first 20 customers');

        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 12);

        $this->get('/')->assertSee('first 12 customers')->assertDontSee('first 20 customers');
    });

    it('does not promise a free allowance the admin has switched off', function () {
        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: false);

        $this->get('/')->assertOk()->assertDontSee('first 20 customers')->assertDontSee('first 0 customers');
    });

    it('sets up the page for phones and for search engines', function () {
        $this->get('/')
            ->assertSee('viewport-fit=cover', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertDontSee('noindex');
    });

    it('offers every shipped language to search engines', function () {
        $page = $this->get('/');

        foreach (config('qistas.locales') as $locale) {
            $page->assertSee('<link rel="alternate" hreflang="'.$locale.'" href="'.url('/').'/?lang='.$locale.'">', false);
        }
        $page->assertSee('hreflang="x-default"', false);
    });

    it('renders right-to-left in Arabic', function () {
        $this->get('/?lang=ar')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
    });

    it('lets people pick a language and a colour mode', function () {
        $this->get('/')->assertSee('?lang=ar', false)->assertSee('data-q-mode-toggle', false);
    });

    it('names the exact payment rule it advertises', function () {
        $this->get('/')->assertSee('oldest instalment first');
    });

    it('answers the questions people ask before signing up', function () {
        $this->get('/')->assertSee('Is it really free?')->assertSee('What happens if I reach the free limit?');
    });
});

describe('the legal pages', function () {
    it('serves the terms and the privacy policy that sign-up links to', function (string $path, string $heading) {
        $this->get($path)->assertOk()->assertSee($heading);
    })->with([['/terms', 'Terms of Service'], ['/privacy', 'Privacy Policy']]);

    it('links to them from the sign-up page', function () {
        $this->get('/register')->assertSee(url('/terms'), false)->assertSee(url('/privacy'), false);
    });
});

describe('crawlers', function () {
    it('publishes a sitemap of the public pages in every language', function () {
        $sitemap = $this->get('/sitemap.xml');

        $sitemap->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        foreach (['/', '/pricing', '/terms', '/privacy'] as $path) {
            $sitemap->assertSee('<loc>'.url($path).'</loc>', false);
        }
        $sitemap->assertSee('hreflang="ar"', false);
    });

    it('keeps the signed-in app, the admin console and the API out of search results', function () {
        $robots = $this->get('/robots.txt');

        $robots->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /app')->assertSee('Disallow: /admin')->assertSee('Disallow: /api')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));
    });
});

describe('error pages', function () {
    it('shows a branded not-found page with a way back', function () {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee(url('/'), false);
    });

    it('speaks the reader’s language on an error', function () {
        $this->get('/no-such-page?lang=ar')->assertNotFound()->assertSee('lang="ar" dir="rtl"', false);
    });

    it('explains a forbidden page', function () {
        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403));

        $this->get('/_test/forbidden')->assertForbidden()->assertSee('You do not have access to this page');
    });

    it('explains an expired session and does not lose the way back', function () {
        Route::middleware('web')->get('/_test/expired', fn () => abort(419));

        $this->get('/_test/expired')->assertStatus(419)->assertSee('Your session expired')->assertSee(route('login'), false);
    });

    it('explains rate limiting', function () {
        Route::middleware('web')->get('/_test/slow-down', fn () => abort(429));

        $this->get('/_test/slow-down')->assertStatus(429)->assertSee('Too many requests');
    });

    it('apologises for a crash without leaking anything', function () {
        Route::middleware('web')->get('/_test/crash', fn () => throw new RuntimeException('secret database host db.internal'));
        config(['app.debug' => false]);

        $this->get('/_test/crash')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('db.internal');
    });

    it('keeps JSON clients on JSON', function () {
        $this->getJson('/no-such-page')->assertNotFound()->assertJsonMissing(['Page not found']);
    });
});
