<?php

use App\Models\User;

/*
| The language button: one standalone control (a globe and the current language in its own script) that opens a short
| list of the five languages, on every page a person sees. It is not part of any account menu.
*/

function languageLinks(string $html): array
{
    preg_match_all('/<a[^>]*\bhreflang="([a-z]{2})"[^>]*href="[^"]*[?&;]lang=([a-z]{2})"/', $html, $found);

    return $found[2];
}

function switcherBlock(string $html): string
{
    preg_match('/<details class="lang"[^>]*data-lang-switcher.*?<\/details>/s', $html, $match);

    return $match[0] ?? '';
}

describe('the button', function () {
    it('shows the current language in its own script, and lists all five with the current one marked', function () {
        $block = switcherBlock($this->get('/')->assertOk()->getContent());

        expect($block)->toContain('data-lang-switcher')->and($block)->toContain('English')
            ->and(languageLinks($block))->toBe(['en', 'ar', 'fr', 'es', 'ur'])
            ->and(substr_count($block, 'aria-current="true"'))->toBe(1);
    });

    it('follows the language of the page', function (string $code, string $name) {
        $block = switcherBlock($this->get('/?lang='.$code)->assertOk()->getContent());

        expect($block)->toMatch('/class="lang-name"[^>]*>\s*'.preg_quote($name, '/').'/u')
            ->and($block)->toMatch('/lang="'.$code.'"[^>]*aria-current="true"/');
    })->with([['en', 'English'], ['ar', 'العربية'], ['fr', 'Français'], ['es', 'Español'], ['ur', 'اردو']]);

    it('names itself for screen readers and says which language is chosen', function () {
        $block = switcherBlock($this->get('/?lang=fr')->getContent());

        expect($block)->toContain('aria-label="Langue : Français"');
    });

    it('is a button of its own, not an item in a menu', function () {
        $block = switcherBlock($this->get('/')->getContent());

        expect($block)->not->toContain('class="menu')->and($block)->not->toContain('role="menu"');
    });
});

describe('where it is', function () {
    it('is on the public site and on the sign-in and sign-up pages', function (string $path) {
        expect(switcherBlock($this->get($path)->assertOk()->getContent()))->not->toBe('');
    })->with(['/', '/pricing', '/login', '/register']);

    it('is in the signed-in app (the sidebar on a computer, the top bar on a phone), and no longer inside the account menu', function () {
        [$user] = owner();
        $html = $this->actingAs($user)->get('/app')->assertOk()->getContent();

        // One in the sidebar and one in the phone's top bar: only one of the two is ever shown.
        expect(substr_count($html, 'data-lang-switcher'))->toBe(2)->and(count(languageLinks($html)))->toBe(10);

        foreach (preg_split('/<div class="menu-panel"/', $html) as $i => $afterPanel) {
            if ($i > 0) {
                expect(substr($afterPanel, 0, strpos($afterPanel, '</div>')))->not->toContain('?lang=');
            }
        }
    });

    it('is in the admin area, outside the account menu', function () {
        $admin = User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

        expect(substr_count($html, 'data-lang-switcher'))->toBe(1)->and(count(languageLinks($html)))->toBe(5);
    });

    it('keeps the page and its other settings when the language is changed', function () {
        $block = switcherBlock($this->get('/pricing?plan=pro')->getContent());

        expect($block)->toContain('plan=pro&amp;lang=ar');
    });
});
