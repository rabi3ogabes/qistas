<?php

use App\Models\User;
use App\Theme\Appearance;
use App\Theme\BrandImages;
use Illuminate\Http\UploadedFile;

/*
| The welcome banner the admin writes: on the website under the header, in the web app at the top of the dashboard,
| in the visitor's language, between its dates, closable when the admin allows it (and remembered as closed).
*/

function bannerAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** Publishes [$banner] for [$surface]. */
function showBanner(User $admin, string $surface, array $banner = [], array $draft = []): void
{
    $banner = array_replace_recursive([
        'enabled' => true, 'tone' => 'gold', 'dismissible' => true, 'cta_url' => '/pricing',
        'text' => ['en' => ['title' => 'Welcome to Qistas', 'message' => 'Every instalment, to the cent.', 'cta_label' => 'See plans'], 'ar' => ['title' => 'مرحبًا بك في قسطاس', 'message' => 'كل قسط بدقة.', 'cta_label' => 'الخطط']],
    ], $banner);

    app(Appearance::class)->saveDraft(['banners' => [$surface => $banner]] + $draft, $admin);
    app(Appearance::class)->publish($admin);
}

/** The banner's markup on a page, or '' when there is none. */
function bannerOf(string $html): string
{
    preg_match('/<aside class="welcome".*?<\/aside>/s', $html, $match);

    return $match[0] ?? '';
}

beforeEach(function () {
    $this->admin = bannerAdmin();
    $this->travelTo('2026-12-05 10:00:00');
});

describe('on the website', function () {
    it('sits under the header of every public page, in the page’s language', function (string $path) {
        showBanner($this->admin, 'website');

        $html = $this->get($path)->assertOk()->getContent();
        $banner = bannerOf($html);

        expect($banner)->toContain('Welcome to Qistas')->toContain('Every instalment, to the cent.')
            ->and(strpos($html, '<aside class="welcome"'))->toBeGreaterThan(strpos($html, 'class="site-header'))->toBeLessThan(strpos($html, '<main'));
    })->with(['/', '/pricing', '/terms']);

    it('speaks Arabic in Arabic, and English where a language has no words of its own', function () {
        showBanner($this->admin, 'website');

        expect(bannerOf($this->get('/?lang=ar')->getContent()))->toContain('مرحبًا بك في قسطاس')->toContain('الخطط')
            ->and(bannerOf($this->get('/?lang=fr')->getContent()))->toContain('Welcome to Qistas');
    });

    it('has its button only when there is somewhere to go', function () {
        showBanner($this->admin, 'website');
        expect(bannerOf($this->get('/')->getContent()))->toContain('href="/pricing"')->toContain('See plans');

        showBanner($this->admin, 'website', ['cta_url' => null]);
        expect(bannerOf($this->get('/')->getContent()))->not->toContain('See plans');
    });

    it('opens a secure address of another site safely', function () {
        showBanner($this->admin, 'website', ['cta_url' => 'https://qistas.example/offer']);

        expect(bannerOf($this->get('/')->getContent()))->toMatch('/href="https:\/\/qistas\.example\/offer"[^>]*rel="noopener noreferrer"/');
    });

    it('is not in the web app', function () {
        showBanner($this->admin, 'website');
        [$owner] = owner();

        expect(bannerOf($this->actingAs($owner)->get('/app')->getContent()))->toBe('');
    });
});

describe('in the web app', function () {
    it('sits at the top of the dashboard, and only there', function () {
        showBanner($this->admin, 'webapp', ['text' => ['en' => ['title' => 'New: instalment tools']]]);
        [$owner] = owner();

        expect(bannerOf($this->actingAs($owner)->get('/app')->getContent()))->toContain('New: instalment tools')
            ->and(bannerOf($this->actingAs($owner)->get('/app/customers')->getContent()))->toBe('')
            ->and(bannerOf($this->get('/')->getContent()))->toBe('');
    });
});

describe('when it shows', function () {
    it('not when it is switched off, before its first day or after its last', function (array $banner) {
        showBanner($this->admin, 'website', $banner);

        expect(bannerOf($this->get('/')->getContent()))->toBe('');
    })->with([
        'switched off' => [['enabled' => false]],
        'not yet' => [['starts_on' => '2026-12-06']],
        'over' => [['ends_on' => '2026-12-04']],
    ]);

    it('not at all before anything is published', function () {
        expect(bannerOf($this->get('/')->getContent()))->toBe('');
    });
});

describe('what it is made of', function () {
    it('shows what was typed as words, never as markup', function () {
        showBanner($this->admin, 'website', ['text' => ['en' => ['title' => 'Tom & "Jerry" pay later', 'message' => "It's 50% off"]]]);
        $banner = bannerOf($this->get('/')->getContent());

        expect($banner)->toContain('Tom &amp; &quot;Jerry&quot; pay later')->toContain('It&#039;s 50% off');
    });

    it('wears the tone the admin chose', function (string $tone) {
        showBanner($this->admin, 'website', ['tone' => $tone]);

        expect(bannerOf($this->get('/')->getContent()))->toContain('data-tone="'.$tone.'"');
    })->with(['gold', 'navy', 'sand', 'sky']);

    it('carries the banner picture when asked to', function () {
        $picture = app(BrandImages::class)->store(UploadedFile::fake()->image('banner.jpg', 800, 300), 'banner', $this->admin);
        showBanner($this->admin, 'website', ['image' => true], ['images' => ['banner' => $picture->id]]);

        expect(bannerOf($this->get('/')->getContent()))->toContain('src="'.$picture->url().'"')->toContain('alt=""');
    });

    it('can be closed when the admin allows it, and is remembered by a key that changes with the banner', function () {
        showBanner($this->admin, 'website');
        $banner = bannerOf($this->get('/')->getContent());
        $key = app(Appearance::class)->live()->banner('website', 'en')['key'];

        expect($banner)->toContain('data-welcome="'.$key.'"')->toContain('data-welcome-close');

        showBanner($this->admin, 'website', ['dismissible' => false]);
        expect(bannerOf($this->get('/')->getContent()))->not->toContain('data-welcome-close');
    });

    it('hides at once, before it is drawn, when this visitor already closed it', function () {
        showBanner($this->admin, 'website');
        $html = $this->get('/')->getContent();

        // A small script right after the banner reads the visitor's choice before the first paint, so it never flashes.
        expect($html)->toMatch('/<\/aside>\s*<script[^>]*>[^<]*q-welcome-[^<]*hidden[^<]*<\/script>/');
    });
});
