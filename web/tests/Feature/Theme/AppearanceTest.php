<?php

use App\Models\AppearanceAsset;
use App\Models\AppearanceVersion;
use App\Models\AuditLog;
use App\Models\User;
use App\Theme\Appearance;
use App\Theme\AppearanceRefused;
use App\Theme\BrandImages;
use App\Theme\ThemeEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/*
| What the admin chooses for the look of the website, the web app and the Android app, and how it is kept: one working
| draft, and published versions that are never changed afterwards. Everything typed here reaches a stylesheet or a page, so
| most of these tests are about what is refused.
*/

function studioAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function appearance(): Appearance
{
    return app(Appearance::class);
}

/** A banner for the website with an English text and an optional Arabic one. */
function websiteBanner(array $override = []): array
{
    return array_replace_recursive([
        'enabled' => true, 'tone' => 'gold', 'dismissible' => true, 'cta_url' => '/pricing',
        'text' => ['en' => ['title' => 'Welcome to Qistas', 'message' => 'Every instalment, to the cent.', 'cta_label' => 'See plans'], 'ar' => ['title' => 'مرحبًا بك في قسطاس', 'message' => 'كل قسط بدقة.', 'cta_label' => 'الخطط']],
    ], $override);
}

beforeEach(function () {
    $this->admin = studioAdmin();
});

describe('the factory look', function () {
    it('is what everyone sees until something is published', function () {
        $live = appearance()->live();

        expect($live->version())->toBe(0)->and($live->tokens())->toBe(ThemeEngine::BASE)->and($live->hasCustomColours())->toBeFalse()
            ->and($live->imageUrl('logo'))->toBeNull()->and($live->banner('website', 'en'))->toBeNull();
    });
});

describe('saving the draft', function () {
    it('keeps the four brand colours, in capitals, and leaves what everyone sees alone', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0f5132', 'accent' => '#d8b04a', 'info' => '', 'bg' => null]], $this->admin);

        expect(appearance()->draft()->pins())->toBe(['light' => ['primary' => '#0F5132', 'accent' => '#D8B04A']])
            ->and(appearance()->live()->version())->toBe(0);
    });

    it('refuses a colour that is not #RRGGBB, and any colour name beyond the four', function (array $colours, string $field) {
        $errors = validationErrors(fn () => appearance()->saveDraft(['colours' => $colours], $this->admin));

        expect($errors)->toHaveKey($field);
    })->with([
        'a word' => [['primary' => 'red'], 'colours.primary'],
        'a way out of the stylesheet' => [['accent' => 'red;} body{display:none'], 'colours.accent'],
        'a short code' => [['info' => '#FFF'], 'colours.info'],
        'a token that is not one of the four' => [['ink' => '#000000'], 'colours'],
    ]);

    it('takes only a light canvas: a dark one would leave text unreadable on the white cards', function () {
        expect(validationErrors(fn () => appearance()->saveDraft(['colours' => ['bg' => '#111111']], $this->admin)))->toHaveKey('colours.bg');

        appearance()->saveDraft(['colours' => ['bg' => '#FBF8F0']], $this->admin);

        expect(appearance()->draft()->pins())->toBe(['light' => ['bg' => '#FBF8F0']]);
    });

    it('keeps a welcome banner with its words in each language', function () {
        appearance()->saveDraft(['banners' => ['website' => websiteBanner()]], $this->admin);
        $saved = appearance()->draft()->banners()['website'];

        expect($saved['enabled'])->toBeTrue()->and($saved['tone'])->toBe('gold')->and($saved['text']['en']['title'])->toBe('Welcome to Qistas')
            ->and($saved['text']['ar']['title'])->toBe('مرحبًا بك في قسطاس')->and($saved['cta_url'])->toBe('/pricing');
    });

    it('needs an English title before a banner can be switched on', function () {
        $errors = validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['text' => ['en' => ['title' => '']]])]], $this->admin));

        expect($errors)->toHaveKey('banners.website.text.en.title');
    });

    it('refuses a call-to-action link that is not the site’s own page or a secure address', function (string $url) {
        expect(validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['cta_url' => $url])]], $this->admin)))
            ->toHaveKey('banners.website.cta_url');
    })->with([
        'script' => ['javascript:alert(1)'],
        'data' => ['data:text/html,<script>alert(1)</script>'],
        'protocol-relative' => ['//evil.example/phish'],
        'insecure' => ['http://example.com'],
        'mail' => ['mailto:a@b.c'],
        'with a space' => ['/pricing now'],
        'with a line break' => ["/pricing\nSet-Cookie: x"],
        'a backslash' => ['/\\evil.example'],
    ]);

    it('accepts the site’s own pages and secure addresses', function (string $url) {
        appearance()->saveDraft(['banners' => ['website' => websiteBanner(['cta_url' => $url])]], $this->admin);

        expect(appearance()->draft()->banners()['website']['cta_url'])->toBe($url);
    })->with([['/register'], ['/pricing?plan=pro'], ['https://qistas.example/offer']]);

    it('takes plain words only, of a sensible length', function (string $field, string $value) {
        $errors = validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['text' => ['en' => [$field => $value]]])]], $this->admin));

        expect($errors)->toHaveKey('banners.website.text.en.'.$field);
    })->with([
        'markup in the title' => ['title', '<script>alert(1)</script>'],
        'a tag in the message' => ['message', 'Hello <b>there</b>'],
        'a title far too long' => ['title', str_repeat('a', 81)],
        'a message far too long' => ['message', str_repeat('a', 241)],
        'a button label far too long' => ['cta_label', str_repeat('a', 31)],
    ]);

    it('knows three places and five languages, and nothing else', function () {
        expect(validationErrors(fn () => appearance()->saveDraft(['banners' => ['admin' => websiteBanner()]], $this->admin)))->toHaveKey('banners')
            ->and(validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['text' => ['de' => ['title' => 'Hallo']]])]], $this->admin)))->toHaveKey('banners.website.text');
    });

    it('refuses an end date before the start date, and a tone it does not have', function () {
        expect(validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['starts_on' => '2026-12-10', 'ends_on' => '2026-12-01'])]], $this->admin)))->toHaveKey('banners.website.ends_on')
            ->and(validationErrors(fn () => appearance()->saveDraft(['banners' => ['website' => websiteBanner(['tone' => 'neon'])]], $this->admin)))->toHaveKey('banners.website.tone');
    });

    it('refuses a picture that does not exist or belongs in another slot', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);

        expect(validationErrors(fn () => appearance()->saveDraft(['images' => ['logo' => (string) Str::uuid()]], $this->admin)))->toHaveKey('images.logo')
            ->and(validationErrors(fn () => appearance()->saveDraft(['images' => ['hero' => $logo->id]], $this->admin)))->toHaveKey('images.hero');

        appearance()->saveDraft(['images' => ['logo' => $logo->id]], $this->admin);

        expect(appearance()->draft()->images())->toBe(['logo' => $logo->id]);
    });
});

describe('publishing', function () {
    it('makes the draft what everyone sees, as a numbered version', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132', 'accent' => '#D8B04A']], $this->admin);

        $one = appearance()->publish($this->admin, 'Green for the launch');
        $live = appearance()->live();

        expect($one->version)->toBe(1)->and($live->version())->toBe(1)->and($live->hasCustomColours())->toBeTrue()
            ->and($live->tokens())->toBe(ThemeEngine::resolve(['light' => ['primary' => '#0F5132', 'accent' => '#D8B04A']]));

        appearance()->saveDraft(['colours' => ['primary' => '#7A1F2B']], $this->admin);

        expect(appearance()->publish($this->admin)->version)->toBe(2)->and(appearance()->live()->version())->toBe(2);
    });

    it('does not change what everyone sees until it is published', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        appearance()->publish($this->admin);
        appearance()->saveDraft(['colours' => ['primary' => '#7A1F2B']], $this->admin);

        expect(appearance()->live()->tokens()['light']['primary'])->toBe('#0F5132');
    });

    it('repairs text that would be unreadable, and says what it moved', function () {
        // A pale primary: white text on it fails, so the text colour is adjusted.
        appearance()->saveDraft(['colours' => ['primary' => '#7FD1B9', 'info' => '#6A1B9A']], $this->admin);
        $version = appearance()->publish($this->admin);
        $failing = array_filter(ThemeEngine::validate(appearance()->live()->tokens()), fn ($check) => ! $check['pass']);

        expect($failing)->toBe([])->and($version->repaired())->not->toBeEmpty()->and($version->repaired()[0])->toHaveKeys(['mode', 'token', 'from', 'to']);
    });

    it('refuses a look that cannot be made readable, and changes nothing', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        appearance()->publish($this->admin);

        // A dark canvas under white cards cannot be repaired (the form would never allow it; this is the safety net).
        $draft = appearance()->draft();
        $draft->forceFill(['pins' => ['light' => ['bg' => '#111111']]])->save();

        expect(fn () => appearance()->publish($this->admin))->toThrow(AppearanceRefused::class)
            ->and(appearance()->live()->version())->toBe(1)->and(AppearanceVersion::where('status', 'published')->count())->toBe(1);
    });

    it('never changes a published version afterwards', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        $published = appearance()->publish($this->admin);

        expect(fn () => $published->forceFill(['note' => 'sneaky'])->save())->toThrow(LogicException::class)
            ->and(fn () => $published->delete())->toThrow(LogicException::class);
    });

    it('lists the versions newest first, with who published each and when', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        appearance()->publish($this->admin, 'First');
        appearance()->saveDraft(['colours' => ['primary' => '#7A1F2B']], $this->admin);
        appearance()->publish($this->admin, 'Second');

        $history = appearance()->history();

        expect($history->pluck('version')->all())->toBe([2, 1])->and($history->first()->note)->toBe('Second')
            ->and($history->first()->published_by_user_id)->toBe($this->admin->id)->and($history->first()->published_at)->not->toBeNull();
    });

    it('can put an older version back, as a new version, leaving the history whole', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        $first = appearance()->publish($this->admin);
        appearance()->saveDraft(['colours' => ['primary' => '#7A1F2B']], $this->admin);
        appearance()->publish($this->admin);

        $back = appearance()->restore($first, $this->admin);

        expect($back->version)->toBe(3)->and(appearance()->live()->tokens()['light']['primary'])->toBe('#0F5132')
            ->and(appearance()->history()->pluck('version')->all())->toBe([3, 2, 1]);
    });

    it('can go back to the factory colours and pictures while keeping the banners', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132'], 'images' => ['logo' => $logo->id], 'banners' => ['website' => websiteBanner()]], $this->admin);
        appearance()->publish($this->admin);

        $reset = appearance()->resetLook($this->admin);

        expect($reset->version)->toBe(2)->and(appearance()->live()->hasCustomColours())->toBeFalse()->and(appearance()->live()->tokens())->toBe(ThemeEngine::BASE)
            ->and(appearance()->live()->imageUrl('logo'))->toBeNull()->and(appearance()->live()->banner('website', 'en'))->not->toBeNull();
    });

    it('is seen at once, not after a cache expires', function () {
        appearance()->live();
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        appearance()->publish($this->admin);

        expect(appearance()->live()->version())->toBe(1);
    });

    it('is written to the audit log, with who and which version', function () {
        appearance()->saveDraft(['colours' => ['primary' => '#0F5132']], $this->admin);
        $published = appearance()->publish($this->admin, 'Green');
        appearance()->restore($published, $this->admin);
        appearance()->resetLook($this->admin);

        $rows = AuditLog::whereIn('action', ['appearance.published', 'appearance.restored', 'appearance.reset'])->orderBy('created_at')->orderBy('id')->get();

        expect($rows->pluck('action')->sort()->values()->all())->toBe(['appearance.published', 'appearance.reset', 'appearance.restored'])
            ->and($rows->every(fn ($row) => $row->user_id === $this->admin->id && isset($row->changes['version'])))->toBeTrue();
    });
});

describe('who may change it', function () {
    it('is the super admin only', function () {
        $staff = User::factory()->create(['platform_role' => 'admin']);
        $person = User::factory()->create();

        expect(Gate::forUser($this->admin)->allows('manage-appearance'))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('manage-appearance'))->toBeFalse()
            ->and(Gate::forUser($person)->allows('manage-appearance'))->toBeFalse();
    });
});

describe('the welcome banner a visitor gets', function () {
    beforeEach(function () {
        $this->travelTo('2026-12-05 10:00:00');
    });

    function publishBanner(User $admin, string $surface, array $banner): void
    {
        appearance()->saveDraft(['banners' => [$surface => $banner]], $admin);
        appearance()->publish($admin);
    }

    it('speaks the visitor’s language, and English where that language has no words', function () {
        publishBanner($this->admin, 'website', websiteBanner());
        $live = appearance()->live();

        expect($live->banner('website', 'ar')['title'])->toBe('مرحبًا بك في قسطاس')->and($live->banner('website', 'fr')['title'])->toBe('Welcome to Qistas')
            ->and($live->banner('website', 'en')['cta_label'])->toBe('See plans')->and($live->banner('website', 'en')['cta_url'])->toBe('/pricing');
    });

    it('belongs to one place only', function () {
        publishBanner($this->admin, 'website', websiteBanner());

        expect(appearance()->live()->banner('webapp', 'en'))->toBeNull()->and(appearance()->live()->banner('mobile', 'en'))->toBeNull();
    });

    it('is gone when it is switched off', function () {
        publishBanner($this->admin, 'website', websiteBanner(['enabled' => false]));

        expect(appearance()->live()->banner('website', 'en'))->toBeNull();
    });

    it('shows from its first day to the end of its last', function () {
        publishBanner($this->admin, 'website', websiteBanner(['starts_on' => '2026-12-06', 'ends_on' => '2026-12-08']));
        $show = fn () => appearance()->live()->banner('website', 'en') !== null;

        expect($show())->toBeFalse();
        $this->travelTo('2026-12-06 00:00:01');
        expect($show())->toBeTrue();
        $this->travelTo('2026-12-08 23:59:59');
        expect($show())->toBeTrue();
        $this->travelTo('2026-12-09 00:00:00');
        expect($show())->toBeFalse();
    });

    it('carries the key a dismissal is remembered by: it changes when the banner changes', function () {
        publishBanner($this->admin, 'website', websiteBanner());
        $before = appearance()->live()->banner('website', 'en')['key'];
        publishBanner($this->admin, 'website', websiteBanner(['text' => ['en' => ['title' => 'Eid offer']]]));

        expect(appearance()->live()->banner('website', 'en')['key'])->not->toBe($before);
    });
});

describe('brand pictures', function () {
    it('are kept as JPEG or PNG, with their size, and found again byte for byte', function () {
        $asset = app(BrandImages::class)->store(UploadedFile::fake()->image('hero.jpg', 1600, 900), 'hero', $this->admin);

        expect($asset->slot)->toBe('hero')->and($asset->mime)->toBe('image/jpeg')->and([$asset->width, $asset->height])->toBe([1600, 900])
            ->and(hash('sha256', $asset->bytes()))->toBe($asset->sha256)->and(getimagesizefromstring($asset->bytes())['mime'])->toBe('image/jpeg');
    });

    it('are made smaller than the slot needs, keeping their proportions', function () {
        $asset = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 3000, 1000), 'logo', $this->admin);

        expect($asset->width)->toBeLessThanOrEqual(600)->and($asset->height)->toBeLessThanOrEqual(240)
            ->and(round($asset->width / $asset->height, 1))->toBe(3.0);
    });

    it('lose their hidden data, including where a phone took them', function () {
        $asset = app(BrandImages::class)->store(upload(jpegBytes(400, 200, withExif: true), 'banner.jpg'), 'banner', $this->admin);

        expect($asset->bytes())->not->toContain('Exif')->and($asset->bytes())->not->toContain('GPS:');
    });

    it('keep the transparency of a PNG logo', function () {
        $image = imagecreatetruecolor(40, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $asset = app(BrandImages::class)->store(upload($png, 'logo.png'), 'logo', $this->admin);
        $copy = imagecreatefromstring($asset->bytes());

        expect($asset->mime)->toBe('image/png')->and((imagecolorat($copy, 0, 0) >> 24) & 0x7F)->toBe(127);
    });

    it('are refused when they are not really pictures, whatever they are called', function (string $bytes, string $name) {
        expect(validationErrors(fn () => app(BrandImages::class)->store(upload($bytes, $name), 'logo', $this->admin)))->toHaveKey('file')
            ->and(AppearanceAsset::count())->toBe(0);
    })->with([
        'a vector picture called a PNG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png'],
        'a web page called a JPEG' => ['<html><script>alert(1)</script></html>', 'logo.jpg'],
        'nothing at all' => ['', 'logo.png'],
        'text' => ['hello', 'logo.png'],
    ]);

    it('are refused when a PDF, or with absurdly many pixels', function () {
        expect(validationErrors(fn () => app(BrandImages::class)->store(upload(pdfBytes(), 'logo.png'), 'logo', $this->admin)))->toHaveKey('file')
            ->and(validationErrors(fn () => app(BrandImages::class)->store(upload(hugePng(7000), 'hero.png'), 'hero', $this->admin)))->toHaveKey('file');
    });

    it('are refused above the upload limit, even when they are perfectly good pictures', function () {
        // A real JPEG, padded with comment segments (which every reader skips) until it weighs more than the limit.
        $jpeg = jpegBytes(400, 200);
        $comment = chr(0xFF).chr(0xFE).pack('n', 65535).str_repeat('A', 65533);
        $heavy = substr($jpeg, 0, 2).str_repeat($comment, intdiv(BrandImages::MAX_BYTES, 65537) + 1).substr($jpeg, 2);

        expect(strlen($heavy))->toBeGreaterThan(BrandImages::MAX_BYTES)->and(getimagesizefromstring($heavy)[0])->toBe(400)
            ->and(validationErrors(fn () => app(BrandImages::class)->store(upload($heavy, 'hero.jpg'), 'hero', $this->admin)))->toHaveKey('file');
    });

    it('are taken as JPEG or PNG only: a real GIF is refused', function () {
        $image = imagecreatetruecolor(30, 30);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();

        expect(getimagesizefromstring($gif)['mime'])->toBe('image/gif')
            ->and(validationErrors(fn () => app(BrandImages::class)->store(upload($gif, 'logo.png'), 'logo', $this->admin)))->toHaveKey('file');
    });

    it('go only in a slot that exists', function () {
        expect(fn () => app(BrandImages::class)->store(UploadedFile::fake()->image('x.png', 10, 10), '../../etc', $this->admin))->toThrow(InvalidArgumentException::class);
    });

    it('get an address that never changes and is never reused', function () {
        $a = app(BrandImages::class)->store(UploadedFile::fake()->image('a.png', 300, 100), 'logo', $this->admin);
        $b = app(BrandImages::class)->store(UploadedFile::fake()->image('a.png', 300, 100), 'logo', $this->admin);

        expect($a->id)->not->toBe($b->id)->and($a->url())->toBe(route('brand.asset', $a));
    });

    it('show up as the address of the live look once published', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        appearance()->saveDraft(['images' => ['logo' => $logo->id]], $this->admin);
        appearance()->publish($this->admin);

        expect(appearance()->live()->imageUrl('logo'))->toBe($logo->url())->and(appearance()->live()->imageUrl('hero'))->toBeNull();
    });
});

it('raises a validation error, not a crash, for anything the form could send', function () {
    expect(fn () => appearance()->saveDraft(['colours' => 'primary'], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => appearance()->saveDraft(['banners' => 'website'], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => appearance()->saveDraft(['images' => ['logo' => ['a']]], $this->admin))->toThrow(ValidationException::class);
});
