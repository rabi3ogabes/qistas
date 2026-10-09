<?php

use App\Models\User;
use App\Theme\Appearance;
use App\Theme\BrandImages;
use App\Theme\ThemeEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| How the chosen look reaches people: a stylesheet of colour variables that every page of the website and the web app
| links (never the admin console), the logo in place of the drawn one, pictures served from addresses that never change,
| and the same palette and the Android banner through the API.
*/

function deliveryAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** Publishes a look with these colours, pictures and banners. */
function publishLook(User $admin, array $draft): void
{
    app(Appearance::class)->saveDraft($draft, $admin);
    app(Appearance::class)->publish($admin);
}

function cssVar(string $token): string
{
    return '--q-'.Str::kebab($token);
}

beforeEach(function () {
    $this->admin = deliveryAdmin();
});

describe('the stylesheet', function () {
    it('is empty while the factory look is on, and no page links it', function () {
        $this->get('/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertDontSee('--q-', false);

        $this->get('/')->assertDontSee('/theme.css', false);
    });

    it('holds every token of the chosen palette for light, dark, and a dark system setting', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132', 'accent' => '#D8B04A']]);
        $tokens = app(Appearance::class)->live()->tokens();
        $css = $this->get('/theme.css?v=1')->assertOk()->getContent();

        foreach (ThemeEngine::BASE['light'] as $token => $_) {
            expect($css)->toContain(cssVar($token).': '.$tokens['light'][$token].';')->toContain(cssVar($token).': '.$tokens['dark'][$token].';');
        }

        expect($css)->toContain(':root {')->toContain(':root[data-q-mode="dark"] {')->toContain('@media (prefers-color-scheme: dark)')
            ->toContain(':root:not([data-q-mode="light"]) {');
    });

    it('contains nothing but colour declarations, so nothing typed can become a rule', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132']]);
        $css = $this->get('/theme.css?v=1')->getContent();

        $rest = preg_replace([
            '/\/\*.*?\*\//s',
            '/--q-[a-z-]+: #[0-9A-F]{6};/',
            '/--q-shadow-[12]: [0-9a-z ,\/.()-]+;/',
            '/:root \{|:root\[data-q-mode="dark"\] \{|@media \(prefers-color-scheme: dark\) \{|:root:not\(\[data-q-mode="light"\]\) \{|\}/',
            '/\s+/',
        ], '', $css);

        expect($rest)->toBe('');
    });

    it('is cached for a year at its versioned address, and briefly without one', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132']]);

        expect($this->get('/theme.css?v=1')->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')
            ->and($this->get('/theme.css')->headers->get('Cache-Control'))->toContain('max-age=60')->not->toContain('immutable')
            ->and($this->get('/theme.css?v=7')->headers->get('Cache-Control'))->not->toContain('immutable');
    });

    it('is linked, after the site’s own styles, by the website, the sign-in pages and the web app, and not by the admin console', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132']]);
        [$owner] = owner();
        $link = 'href="'.route('theme.css', ['v' => 1]).'"';

        foreach (['/', '/pricing', '/login'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            expect($html)->toContain($link, $path);
            expect(strpos($html, $link))->toBeGreaterThan(strpos($html, 'rel="stylesheet"'));
        }

        expect($this->actingAs($owner)->get('/app')->assertOk()->getContent())->toContain($link)
            ->and($this->actingAs($this->admin)->get('/admin')->assertOk()->getContent())->not->toContain('/theme.css');
    });

    it('tints the phone’s status bar with the chosen canvas', function () {
        publishLook($this->admin, ['colours' => ['bg' => '#FBF8F0']]);
        $dark = app(Appearance::class)->live()->tokens()['dark']['bg'];

        $this->get('/')->assertSee('<meta name="theme-color" media="(prefers-color-scheme: light)" content="#FBF8F0">', false)
            ->assertSee('<meta name="theme-color" media="(prefers-color-scheme: dark)" content="'.$dark.'">', false);
    });
});

describe('the logo', function () {
    it('is the drawn Qistas logo until one is chosen', function () {
        $this->get('/')->assertSee('#q-lockup', false)->assertDontSee('class="q-logo q-logo-img', false);
    });

    it('is the chosen picture on the website, the sign-in pages and the web app, and never in the admin console', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        publishLook($this->admin, ['images' => ['logo' => $logo->id]]);
        [$owner] = owner();
        $img = 'src="'.$logo->url().'"';

        expect($this->get('/')->getContent())->toContain($img)->toContain('alt="'.config('qistas.app_name').'"')
            ->and($this->get('/login')->getContent())->toContain($img)
            ->and($this->actingAs($owner)->get('/app')->getContent())->toContain($img)
            ->and($this->actingAs($this->admin)->get('/admin')->getContent())->not->toContain($img)->toContain('#q-lockup');
    });

    it('keeps its proportions at the header’s height, whatever size it was uploaded at', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 600, 200), 'logo', $this->admin);
        publishLook($this->admin, ['images' => ['logo' => $logo->id]]);

        preg_match('/<header class="site-header.*?<\/header>/s', $this->get('/')->getContent(), $header);

        expect($header[0])->toContain('src="'.$logo->url().'" alt="'.config('qistas.app_name').'" width="90" height="30"');
    });

    it('has a dark-mode version when one is chosen', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        $dark = app(BrandImages::class)->store(UploadedFile::fake()->image('logo-dark.png', 300, 100), 'logo_dark', $this->admin);
        publishLook($this->admin, ['images' => ['logo' => $logo->id, 'logo_dark' => $dark->id]]);

        $this->get('/')->assertSee('src="'.$logo->url().'"', false)->assertSee('src="'.$dark->url().'"', false)
            ->assertSee('q-logo-light', false)->assertSee('q-logo-dark', false);
    });
});

describe('the logo on a dark panel', function () {
    it('is the dark-background version on the sign-in page’s dark side', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        $dark = app(BrandImages::class)->store(UploadedFile::fake()->image('logo-dark.png', 300, 100), 'logo_dark', $this->admin);
        publishLook($this->admin, ['images' => ['logo' => $logo->id, 'logo_dark' => $dark->id]]);

        preg_match('/<aside class="auth-aside".*?<\/aside>/s', $this->get('/login')->getContent(), $aside);

        expect($aside[0])->toContain('src="'.$dark->url().'"')->not->toContain('src="'.$logo->url().'"');
    });
});

describe('the hero picture', function () {
    it('sits behind the home page’s headline when one is chosen, under a veil that keeps the words readable', function () {
        $hero = app(BrandImages::class)->store(UploadedFile::fake()->image('hero.jpg', 1600, 900), 'hero', $this->admin);

        preg_match('/<section class="hero[^"]*".*?<\/section>/s', $this->get('/')->getContent(), $plain);
        expect($plain[0])->not->toContain('hero-pic');

        publishLook($this->admin, ['images' => ['hero' => $hero->id]]);
        preg_match('/<section class="hero[^"]*".*?<\/section>/s', $this->get('/')->getContent(), $section);

        expect($section[0])->toStartWith('<section class="hero hero-has-pic"')
            ->toMatch('/<img class="hero-pic" src="'.preg_quote($hero->url(), '/').'" alt="" width="1600" height="900"[^>]*fetchpriority="high"/');
    });
});

describe('a brand picture', function () {
    it('is served as stored, with a type, cached for a year and never sniffed', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        $response = $this->get($logo->url())->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/png')->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')
            ->and(hash('sha256', $response->getContent()))->toBe($logo->sha256);
    });

    it('is not found for an unknown or malformed address', function () {
        $this->get('/brand-assets/'.Str::uuid())->assertNotFound();
        $this->get('/brand-assets/not-a-uuid')->assertNotFound();
        $this->get('/brand-assets/1%27%20OR%201=1')->assertNotFound();

        // On PostgreSQL a malformed id that reached the query would be a 500, not a 404: the route itself refuses it.
        expect(Route::getRoutes()->getByName('brand.asset')->wheres)->toHaveKey('asset');
    });
});

describe('the appearance API', function () {
    it('gives the factory look before anything is published, with no sign-in', function () {
        $this->getJson('/api/v1/appearance')->assertOk()
            ->assertJsonPath('data.version', 0)->assertJsonPath('data.custom', false)
            ->assertJsonPath('data.tokens', ThemeEngine::BASE)->assertJsonPath('data.logo_url', null)->assertJsonPath('data.banner', null);
    });

    it('gives the resolved palette, the logo and the Android banner in the app’s language', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        publishLook($this->admin, [
            'colours' => ['primary' => '#0F5132'],
            'images' => ['logo' => $logo->id],
            'banners' => ['mobile' => ['enabled' => true, 'tone' => 'navy', 'text' => ['en' => ['title' => 'Eid offer', 'message' => 'Three months of Pro free.'], 'ar' => ['title' => 'عرض العيد']]]],
        ]);

        $this->getJson('/api/v1/appearance', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.custom', true)
            ->assertJsonPath('data.tokens', app(Appearance::class)->live()->tokens())
            ->assertJsonPath('data.logo_url', $logo->url())
            ->assertJsonPath('data.banner.title', 'عرض العيد')->assertJsonPath('data.banner.tone', 'navy');

        $this->getJson('/api/v1/appearance', ['Accept-Language' => 'fr'])->assertJsonPath('data.banner.title', 'Eid offer');
    });

    it('never sends the website’s or the web app’s banner to the phone', function () {
        publishLook($this->admin, ['banners' => ['website' => ['enabled' => true, 'text' => ['en' => ['title' => 'Website only']]]]]);

        $this->getJson('/api/v1/appearance')->assertJsonPath('data.banner', null);
    });

    it('answers 304 when the app already has this look', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132']]);
        $etag = $this->getJson('/api/v1/appearance')->assertOk()->headers->get('ETag');

        expect($etag)->not->toBeEmpty();
        $this->getJson('/api/v1/appearance', ['If-None-Match' => $etag])->assertStatus(304);

        publishLook($this->admin, ['colours' => ['primary' => '#7A1F2B']]);
        $this->getJson('/api/v1/appearance', ['If-None-Match' => $etag])->assertOk();
    });

    it('recognises its tag after the CDN marked it as compressed', function () {
        publishLook($this->admin, ['colours' => ['primary' => '#0F5132']]);
        $etag = $this->getJson('/api/v1/appearance')->headers->get('ETag');

        // Vercel answers gzip and rewrites "abc" as "abc-gzip"; the app then sends that back.
        $this->getJson('/api/v1/appearance', ['If-None-Match' => substr($etag, 0, -1).'-gzip"'])->assertStatus(304);
    });
});
