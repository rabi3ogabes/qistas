<?php

use App\Models\AppearanceAsset;
use App\Models\AppearanceVersion;
use App\Models\User;
use App\Theme\Appearance;
use App\Theme\AppearanceView;
use App\Theme\BrandImages;
use App\Theme\Presets;
use App\Theme\ThemeEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
| The Appearance page of the admin area: where a super admin chooses the colours, the pictures and the welcome banners,
| sees them in a preview, publishes them, and goes back to an older look. Other staff may look but not change; the page
| itself always keeps the Qistas look, so a bad choice can never hide the page that fixes it.
*/

function studioStaff(string $role = 'super_admin', string $name = 'Layla Haddad'): User
{
    return User::factory()->create(['name' => $name, 'platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function studio(?User $who = null): TestResponse
{
    return test()->actingAs($who ?? studioStaff())->get('/admin/appearance')->assertOk();
}

/** The form fields a browser sends for the draft as it is, so a test changes only what it means to change. */
function studioForm(array $changes = []): array
{
    return array_replace_recursive([
        'colours' => ['primary' => '', 'accent' => '', 'info' => '', 'bg' => ''],
        'banners' => [
            'website' => ['enabled' => '0', 'tone' => 'gold', 'dismissible' => '1', 'image' => '0', 'cta_url' => '', 'starts_on' => '', 'ends_on' => '', 'text' => ['en' => ['title' => '', 'message' => '', 'cta_label' => '']]],
        ],
    ], $changes);
}

function liveLook(): AppearanceView
{
    app()->forgetScopedInstances();

    return app(Appearance::class)->live();
}

describe('who may use it', function () {
    it('is for the platform team only, with a second factor', function () {
        $this->get('/admin/appearance')->assertRedirect(route('login'));

        [$owner] = owner();
        $this->actingAs($owner)->get('/admin/appearance')->assertNotFound();

        $unsafe = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($unsafe)->get('/admin/appearance')->assertRedirect(route('security'));
    });

    it('lets other staff look, but not change anything', function () {
        $admin = studioStaff('admin');
        $html = studio($admin)->getContent();

        expect($html)->toContain('Only a super admin can change the look.')
            ->toMatch('/<fieldset[^>]*class="studio-fields"[^>]*disabled/')
            ->not->toMatch('/<button[^>]*\sdata-publish[\s>]/');

        $this->actingAs($admin)->post('/admin/appearance/draft', studioForm(['colours' => ['primary' => '#0F5132']]))->assertForbidden();
        $this->actingAs($admin)->post('/admin/appearance/publish', studioForm())->assertForbidden();
        $this->actingAs($admin)->post('/admin/appearance/pictures/logo', ['file' => UploadedFile::fake()->image('logo.png', 300, 100)])->assertForbidden();
        $this->actingAs($admin)->post('/admin/appearance/reset')->assertForbidden();
        $this->actingAs($admin)->post('/admin/appearance/discard')->assertForbidden();

        expect(AppearanceVersion::query()->where('status', 'published')->count())->toBe(0);
    });

    it('is marked as the current page in the menu', function () {
        preg_match('/<aside id="admin-nav".*?<\/aside>/s', studio()->getContent(), $rail);

        expect($rail[0])->toMatch('/href="'.preg_quote(route('admin.appearance.index'), '/').'"[^>]*aria-current="page"/');
    });
});

describe('the page', function () {
    it('shows the draft: its colours, its banner words and its pictures', function () {
        $admin = studioStaff();
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $admin);
        app(Appearance::class)->saveDraft([
            'colours' => ['primary' => '#0F5132'],
            'images' => ['logo' => $logo->id],
            'banners' => ['webapp' => ['enabled' => true, 'text' => ['en' => ['title' => 'New: quarterly plans'], 'ar' => ['title' => 'جديد: خطط ربع سنوية']]]],
        ], $admin);

        $html = studio($admin)->getContent();

        expect($html)->toContain('name="colours[primary]" value="#0F5132"')
            ->toContain('value="New: quarterly plans"')->toContain('value="جديد: خطط ربع سنوية"')
            ->toContain('src="'.$logo->url().'"');
    });

    it('offers the factory colour where none is chosen', function () {
        expect(studio()->getContent())->toContain('name="colours[accent]" value="" placeholder="'.ThemeEngine::BASE['light']['accent'].'"');
    });

    it('never wears the chosen look itself, only its previews do', function () {
        $admin = studioStaff();
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $admin);
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132'], 'images' => ['logo' => $logo->id]], $admin);
        app(Appearance::class)->publish($admin);

        $html = studio($admin)->getContent();
        preg_match('/<aside id="admin-nav".*?<\/aside>/s', $html, $rail);

        expect($html)->not->toContain('/theme.css')
            ->and($rail[0])->toContain('#q-lockup')->not->toContain($logo->url())
            // The preview carries the chosen colours on its own canvas.
            ->and($html)->toMatch('/data-preview[^>]*>/')->toContain('--q-primary: #0F5132;');
    });

    it('says when the draft differs from what everyone sees', function () {
        $admin = studioStaff();

        $stateOf = function () use ($admin): string {
            preg_match('/<p class="studio-state".*?<\/p>/s', studio($admin)->getContent(), $line);

            return $line[0];
        };

        expect($stateOf())->toContain('data-state="live"')->toContain('Everything is published.');

        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132']], $admin);

        expect($stateOf())->toContain('data-state="draft"')->toContain('You have changes that are not published yet.');
    });
});

describe('saving the draft', function () {
    it('keeps colours and banners in every language, and publishes nothing', function () {
        $admin = studioStaff();

        $this->actingAs($admin)->post('/admin/appearance/draft', studioForm([
            'colours' => ['primary' => '#0f5132', 'accent' => '#C8A46A'],
            'banners' => [
                'website' => ['enabled' => '1', 'tone' => 'navy', 'cta_url' => '/pricing', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31',
                    'text' => ['en' => ['title' => 'Eid offer', 'message' => 'Three months of Pro.', 'cta_label' => 'See plans'], 'ar' => ['title' => 'عرض العيد']]],
                'mobile' => ['enabled' => '1', 'tone' => 'sky', 'dismissible' => '0', 'text' => ['en' => ['title' => 'Welcome back']]],
            ],
        ]))->assertRedirect(route('admin.appearance.index'))->assertSessionHas('status', 'Draft saved. Publish when you are ready.');

        $draft = app(Appearance::class)->draft();

        expect($draft->pins())->toBe(['light' => ['primary' => '#0F5132', 'accent' => '#C8A46A']])
            ->and($draft->banners()['website'])->toMatchArray(['enabled' => true, 'tone' => 'navy', 'cta_url' => '/pricing', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31'])
            ->and($draft->banners()['website']['text'])->toBe(['en' => ['title' => 'Eid offer', 'message' => 'Three months of Pro.', 'cta_label' => 'See plans'], 'ar' => ['title' => 'عرض العيد']])
            ->and($draft->banners()['mobile'])->toMatchArray(['enabled' => true, 'tone' => 'sky', 'dismissible' => false])
            ->and(liveLook()->version())->toBe(0);
    });

    it('fills the colours from a preset', function () {
        $this->actingAs(studioStaff())->post('/admin/appearance/draft', studioForm() + ['preset' => 'emerald'])->assertRedirect();

        expect(app(Appearance::class)->draft()->pins())->toBe(['light' => Presets::LIST['emerald']['colours']]);
    });

    it('goes back to the factory colours with the Qistas preset', function () {
        $admin = studioStaff();
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132']], $admin);

        $this->actingAs($admin)->post('/admin/appearance/draft', studioForm(['colours' => ['primary' => '#0F5132']]) + ['preset' => 'qistas'])->assertRedirect();

        expect(app(Appearance::class)->draft()->pins())->toBe([]);
    });

    it('refuses what cannot be shown safely, says why beside the field, and keeps what was typed', function () {
        $admin = studioStaff();
        $sent = studioForm([
            'colours' => ['primary' => 'red;} body{display:none'],
            'banners' => ['website' => ['enabled' => '1', 'cta_url' => 'javascript:alert(1)', 'text' => ['en' => ['title' => 'Kept <b>words</b>']]]],
        ]);

        $this->actingAs($admin)->from('/admin/appearance')->post('/admin/appearance/draft', $sent)
            ->assertRedirect('/admin/appearance')
            ->assertSessionHasErrors(['colours.primary', 'banners.website.cta_url', 'banners.website.text.en.title']);

        $html = $this->actingAs($admin)->get('/admin/appearance')->getContent();

        expect($html)->toContain('Use a colour written like #0B1F44.')
            ->toContain('value="red;} body{display:none"')
            ->toContain('value="Kept &lt;b&gt;words&lt;/b&gt;"')
            ->and(app(Appearance::class)->draft()->pins())->toBe([]);
    });

    it('takes a picture sent with the form, and removes one when asked', function () {
        $admin = studioStaff();

        $this->actingAs($admin)->post('/admin/appearance/draft', studioForm() + ['pictures' => ['hero' => UploadedFile::fake()->image('hero.jpg', 1600, 900)]])->assertRedirect();
        $hero = app(Appearance::class)->draft()->images()['hero'] ?? null;

        expect($hero)->not->toBeNull();

        $this->actingAs($admin)->post('/admin/appearance/draft', studioForm() + ['remove' => ['hero' => '1']])->assertRedirect();

        expect(app(Appearance::class)->draft()->images())->not->toHaveKey('hero');
    });

    it('saves nothing at all when a picture is refused', function () {
        $admin = studioStaff();

        $this->actingAs($admin)->from('/admin/appearance')->post('/admin/appearance/draft', studioForm(['colours' => ['primary' => '#0F5132']]) + ['pictures' => ['logo' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf')]])
            ->assertSessionHasErrors('pictures.logo');

        expect(app(Appearance::class)->draft()->pins())->toBe([])->and(AppearanceAsset::query()->count())->toBe(0);
    });
});

describe('a picture on its own', function () {
    it('is stored and put in the draft at once, and the script gets its address', function () {
        $admin = studioStaff();

        $response = $this->actingAs($admin)->postJson('/admin/appearance/pictures/logo', ['file' => UploadedFile::fake()->image('logo.png', 900, 300)])->assertCreated();
        $id = app(Appearance::class)->draft()->images()['logo'];

        expect($response->json('data.id'))->toBe($id)
            ->and($response->json('data.url'))->toBe(route('brand.asset', ['asset' => $id]))
            ->and($response->json('data.width'))->toBe(600);
    });

    it('is refused with a reason the script can show', function () {
        $this->actingAs(studioStaff())->postJson('/admin/appearance/pictures/logo', ['file' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs(studioStaff(name: 'Second'))->postJson('/admin/appearance/pictures/favicon', ['file' => UploadedFile::fake()->image('a.png')])->assertNotFound();
    });
});

describe('publishing', function () {
    it('saves what is on the form, then makes it what everyone sees', function () {
        $admin = studioStaff();

        $this->actingAs($admin)->post('/admin/appearance/publish', studioForm(['colours' => ['primary' => '#0F5132']]) + ['note' => 'Green for the launch'])
            ->assertRedirect(route('admin.appearance.index'))
            ->assertSessionHas('status', 'Published. Everyone sees version 1 now.');

        $version = app(Appearance::class)->history()->first();

        expect(liveLook()->tokens()['light']['primary'])->toBe('#0F5132')
            ->and($version->note)->toBe('Green for the launch');
    });

    it('tells the admin which colours were adjusted to keep every text readable', function () {
        $admin = studioStaff();

        $this->actingAs($admin)->post('/admin/appearance/publish', studioForm(['colours' => ['info' => '#9CC3F5']]))->assertRedirect();

        $html = $this->actingAs($admin)->get('/admin/appearance')->getContent();
        $moved = app(Appearance::class)->history()->first()->repaired();

        expect($moved)->not->toBeEmpty()
            ->and($html)->toContain('To keep every text readable, these colours were adjusted:')
            ->toContain($moved[0]['from'])->toContain($moved[0]['to']);
    });

    it('does not publish when the form has a mistake', function () {
        $this->actingAs(studioStaff())->post('/admin/appearance/publish', studioForm(['colours' => ['bg' => '#101010']]))
            ->assertSessionHasErrors('colours.bg');

        expect(liveLook()->version())->toBe(0);
    });

    it('lists every version with who published it, when, and its note; the live one is marked', function () {
        $layla = studioStaff(name: 'Layla Haddad');
        $omar = studioStaff(name: 'Omar Saleh');
        $this->travelTo('2026-10-01 09:30');
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132']], $layla);
        app(Appearance::class)->publish($layla, 'Green');
        $this->travelTo('2026-10-03 16:05');
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#4A1526']], $omar);
        app(Appearance::class)->publish($omar, 'Bordeaux');

        preg_match('/<ol class="studio-history".*?<\/ol>/s', studio($layla)->getContent(), $list);

        expect($list[0])->toContain('Version 2')->toContain('Omar Saleh')->toContain('Bordeaux')
            ->toContain('Version 1')->toContain('Layla Haddad')->toContain('Green')
            ->and(strpos($list[0], 'Version 2'))->toBeLessThan(strpos($list[0], 'Version 1'))
            ->and(substr_count($list[0], 'data-restore'))->toBe(1);

        // Only the newest is marked as what everyone sees.
        preg_match_all('/<li class="version"\s*(aria-current="true")?\s*>(.*?)<\/li>/s', $list[0], $rows, PREG_SET_ORDER);
        $live = array_values(array_filter($rows, fn (array $row) => $row[1] !== ''));

        expect($live)->toHaveCount(1)
            ->and($live[0][2])->toContain('Version 2')->toContain('Live');
    });
});

describe('going back', function () {
    it('restores an older version as a new one', function () {
        $admin = studioStaff();
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132']], $admin);
        app(Appearance::class)->publish($admin);
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#4A1526']], $admin);
        app(Appearance::class)->publish($admin);

        $this->actingAs($admin)->post('/admin/appearance/versions/1/restore')
            ->assertRedirect(route('admin.appearance.index'))->assertSessionHas('status', 'Version 1 is back, published as version 3.');

        expect(liveLook()->version())->toBe(3)->and(liveLook()->tokens()['light']['primary'])->toBe('#0F5132');

        $this->actingAs($admin)->post('/admin/appearance/versions/99/restore')->assertNotFound();
    });

    it('resets to the Qistas look, keeping the banners', function () {
        $admin = studioStaff();
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132'], 'banners' => ['website' => ['enabled' => true, 'text' => ['en' => ['title' => 'Still here']]]]], $admin);
        app(Appearance::class)->publish($admin);

        $this->actingAs($admin)->post('/admin/appearance/reset')->assertRedirect()->assertSessionHas('status', 'The Qistas look is back, published as version 2.');

        expect(liveLook()->tokens())->toBe(ThemeEngine::BASE)->and(liveLook()->banner('website', 'en')['title'])->toBe('Still here');
    });

    it('throws away unpublished changes', function () {
        $admin = studioStaff();
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#0F5132']], $admin);
        app(Appearance::class)->publish($admin);
        app(Appearance::class)->saveDraft(['colours' => ['primary' => '#4A1526'], 'banners' => ['mobile' => ['enabled' => true, 'text' => ['en' => ['title' => 'Draft only']]]]], $admin);

        $this->actingAs($admin)->post('/admin/appearance/discard')->assertRedirect()->assertSessionHas('status', 'Unpublished changes were thrown away.');

        expect(app(Appearance::class)->draft()->pins())->toBe(['light' => ['primary' => '#0F5132']])
            ->and(app(Appearance::class)->draft()->banners())->not->toHaveKey('mobile');
    });
});

describe('the live palette check', function () {
    it('answers the colours the server would publish, both modes, with what it would adjust', function () {
        $response = $this->actingAs(studioStaff('admin'))->getJson('/admin/appearance/palette?'.http_build_query(['primary' => '#0F5132', 'info' => '#9CC3F5']))->assertOk();
        $expected = ThemeEngine::autoFix(ThemeEngine::resolve(['light' => ['primary' => '#0F5132', 'info' => '#9CC3F5']], repair: false));

        expect($response->json('data.tokens'))->toBe($expected['tokens'])
            ->and($response->json('data.changed'))->toBe($expected['changed'])
            ->and($response->json('data.contrast.info.pass'))->toBeFalse()
            ->and($response->json('data.contrast.primary.pass'))->toBeTrue();
    });

    it('refuses what is not a colour, and is not for customers', function () {
        $this->actingAs(studioStaff())->getJson('/admin/appearance/palette?primary=red;}')->assertUnprocessable();
        $this->actingAs(studioStaff(name: 'Other'))->getJson('/admin/appearance/palette?bg=%23111111')->assertUnprocessable();

        [$owner] = owner();
        $this->actingAs($owner)->getJson('/admin/appearance/palette?primary=%230F5132')->assertNotFound();
    });
});

describe('the presets', function () {
    it('each make a readable look that can be published', function (string $preset) {
        $admin = studioStaff();
        $colours = Presets::LIST[$preset]['colours'];

        app(Appearance::class)->saveDraft(['colours' => $colours], $admin);
        app(Appearance::class)->publish($admin);

        $failing = array_filter(ThemeEngine::validate(liveLook()->tokens()), fn (array $check) => ! $check['pass'] && $check['blocking']);

        expect($failing)->toBe([]);
    })->with(array_keys(array_filter(Presets::LIST, fn (array $p) => $p['colours'] !== [])));
});
