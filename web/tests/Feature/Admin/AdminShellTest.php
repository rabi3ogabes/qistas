<?php

use App\Models\User;

/*
| The admin area's frame: one menu panel on the right (a slide-in panel on a phone), holding the pages, the places to go
| next and the person's own controls. These tests pin what is in it and where each thing is; the look is checked by eye.
*/

function shellAdmin(bool $withWorkspace = false, string $email = 'layla@example.com'): User
{
    $admin = User::factory()->create([
        'name' => 'Layla Haddad', 'email' => $email, 'platform_role' => 'super_admin',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(),
    ]);

    if ($withWorkspace) {
        workspaceOn('pro')->users()->attach($admin->id, ['role' => 'owner']);
    }

    return $admin;
}

/** The menu panel's markup, or '' when there is none. */
function railOf(string $html): string
{
    preg_match('/<aside[^>]*id="admin-nav".*?<\/aside>/s', $html, $match);

    return $match[0] ?? '';
}

describe('the menu panel', function () {
    it('is on every admin page, once, and the old top bar is gone', function (string $path) {
        $html = $this->actingAs(shellAdmin())->get($path)->assertOk()->getContent();

        expect(substr_count($html, 'id="admin-nav"'))->toBe(1)
            ->and($html)->not->toContain('class="admin-top"')->not->toContain('admin-top-inner');
    })->with(['/admin', '/admin/features']);

    it('lists the admin pages, and the places to go next', function () {
        $rail = railOf($this->actingAs(shellAdmin())->get('/admin')->getContent());

        foreach ([route('admin.home'), route('admin.features.index'), route('home'), route('security')] as $href) {
            expect($rail)->toContain('href="'.$href.'"');
        }
    });

    it('offers the app only to someone who has a workspace', function () {
        $without = railOf($this->actingAs(shellAdmin())->get('/admin')->getContent());
        $with = railOf($this->actingAs(shellAdmin(withWorkspace: true, email: 'omar@example.com'))->get('/admin')->getContent());

        expect($without)->not->toContain('href="'.route('app.dashboard').'"')
            ->and($with)->toContain('href="'.route('app.dashboard').'"');
    });

    it('marks the page that is open, and only that one', function (string $path, string $current, string $other) {
        $rail = railOf($this->actingAs(shellAdmin())->get($path)->getContent());

        expect(substr_count($rail, 'aria-current="page"'))->toBe(1)
            ->and($rail)->toMatch('/href="'.preg_quote(route($current), '/').'"[^>]*aria-current="page"/')
            ->and($rail)->not->toMatch('/href="'.preg_quote(route($other), '/').'"[^>]*aria-current="page"/');
    })->with([
        ['/admin', 'admin.home', 'admin.features.index'],
        ['/admin/features', 'admin.features.index', 'admin.home'],
    ]);

    it('names its groups for a screen reader', function () {
        $rail = railOf($this->actingAs(shellAdmin())->get('/admin')->getContent());

        expect(substr_count($rail, '<nav '))->toBeGreaterThanOrEqual(2)->and($rail)->toMatch('/<nav[^>]*aria-label="[^"]+"/');
    });

    it('holds the person’s own controls: who they are, language, light or dark, and sign out', function () {
        $rail = railOf($this->actingAs(shellAdmin())->get('/admin')->getContent());

        expect($rail)->toContain('Layla Haddad')->toContain('layla@example.com')
            ->and($rail)->toContain('data-lang-switcher')->toContain('data-q-mode-toggle')
            ->and($rail)->toContain('action="'.route('logout').'"');
    });

    it('opens from a menu button on a phone, with no script needed', function () {
        $html = $this->actingAs(shellAdmin())->get('/admin')->getContent();

        expect($html)->toMatch('/<a[^>]*href="#admin-nav"[^>]*>/')
            ->and(railOf($html))->toMatch('/<a[^>]*href="#main"[^>]*class="[^"]*admin-scrim/s');
    });

    it('reads right to left in Arabic and still has the same panel', function () {
        $html = $this->actingAs(shellAdmin())->get('/admin?lang=ar')->assertOk()->getContent();

        expect($html)->toContain('dir="rtl"')->and(railOf($html))->toContain('href="'.route('admin.features.index').'"');
    });
});
