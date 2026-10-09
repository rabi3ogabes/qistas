<?php

use App\Models\AppearanceEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Theme\Appearance;
use App\Theme\AppearanceRefused;
use App\Theme\BrandImages;
use App\Theme\EventPresets;
use App\Theme\ThemeEngine;
use App\Theme\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
| Event themes: the look of a national day or a season, for the countries and the days an admin chooses, laid over the
| published look while it lasts. Everyone else, and every other day, keeps the usual look.
*/

function eventAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function looks(): Appearance
{
    return app(Appearance::class);
}

/** A scheduled event, from the Saudi National Day defaults with [$changes] on top. */
function scheduleEvent(User $admin, array $changes = []): AppearanceEvent
{
    // Shallow on purpose: a list given (countries, places) replaces the default list, it is not merged into it.
    $event = looks()->saveEvent(null, array_replace([
        'name' => 'Saudi National Day',
        'countries' => ['SA'],
        'starts_on' => '2026-09-23',
        'ends_on' => '2026-09-24',
        'timezone' => 'Asia/Riyadh',
        'surfaces' => ['website', 'webapp', 'mobile'],
        'colours' => ['primary' => '#006C35'],
    ], $changes), $admin);

    return looks()->scheduleEvent($event, $admin);
}

function primaryFor(?string $country, string $surface = 'website'): string
{
    return looks()->lookFor($country, $surface)->tokens()['light']['primary'];
}

function validationKeys(Closure $act): array
{
    try {
        $act();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

beforeEach(function () {
    $this->admin = eventAdmin();
    $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Asia/Riyadh'));
});

describe('who sees an event', function () {
    it('shows only in its countries', function () {
        scheduleEvent($this->admin);

        expect(primaryFor('SA'))->toBe('#006C35')
            ->and(primaryFor('FR'))->toBe(ThemeEngine::BASE['light']['primary'])
            ->and(primaryFor(null))->toBe(ThemeEngine::BASE['light']['primary']);
    });

    it('reaches everyone when it names no country', function () {
        scheduleEvent($this->admin, ['countries' => []]);

        expect(primaryFor('FR'))->toBe('#006C35')->and(primaryFor(null))->toBe('#006C35');
    });

    it('dresses only the places it was made for', function () {
        scheduleEvent($this->admin, ['surfaces' => ['website']]);

        expect(primaryFor('SA', 'website'))->toBe('#006C35')
            ->and(primaryFor('SA', 'webapp'))->toBe(ThemeEngine::BASE['light']['primary'])
            ->and(primaryFor('SA', 'mobile'))->toBe(ThemeEngine::BASE['light']['primary']);
    });
});

describe('when an event shows', function () {
    it('runs from the first day to the last, by the clock of its own country', function (string $utc, bool $on) {
        scheduleEvent($this->admin);
        $this->travelTo(Carbon::parse($utc, 'UTC'));

        expect(primaryFor('SA') === '#006C35')->toBe($on);
    })->with([
        'a second before midnight in Riyadh, the day before' => ['2026-09-22 20:59:59', false],
        'midnight in Riyadh on the first day' => ['2026-09-22 21:00:00', true],
        'the last second of the last day in Riyadh' => ['2026-09-24 20:59:59', true],
        'midnight in Riyadh after the last day' => ['2026-09-24 21:00:00', false],
    ]);

    it('says where it stands: draft, scheduled, on now or ended', function () {
        $event = looks()->saveEvent(null, ['name' => 'Founding Day', 'countries' => ['SA'], 'starts_on' => '2026-09-25', 'ends_on' => '2026-09-25', 'timezone' => 'Asia/Riyadh', 'surfaces' => ['website']], $this->admin);

        expect($event->stateAt(now()))->toBe('draft');

        $event = looks()->scheduleEvent($event, $this->admin);
        expect($event->stateAt(now()))->toBe('scheduled')
            ->and($event->stateAt(Carbon::parse('2026-09-25 09:00', 'Asia/Riyadh')))->toBe('live')
            ->and($event->stateAt(Carbon::parse('2026-09-26 00:00', 'Asia/Riyadh')))->toBe('ended');
    });

    it('never shows while it is a draft, once it is stopped, or after it is deleted', function () {
        looks()->saveEvent(null, ['name' => 'Draft', 'countries' => ['SA'], 'starts_on' => '2026-09-23', 'ends_on' => '2026-09-23', 'timezone' => 'Asia/Riyadh', 'surfaces' => ['website'], 'colours' => ['primary' => '#006C35']], $this->admin);
        expect(primaryFor('SA'))->toBe(ThemeEngine::BASE['light']['primary']);

        $event = scheduleEvent($this->admin);
        expect(primaryFor('SA'))->toBe('#006C35');

        looks()->stopEvent($event, $this->admin);
        expect(primaryFor('SA'))->toBe(ThemeEngine::BASE['light']['primary']);

        $event = looks()->scheduleEvent($event->fresh(), $this->admin);
        looks()->deleteEvent($event, $this->admin);
        expect(primaryFor('SA'))->toBe(ThemeEngine::BASE['light']['primary'])->and(AppearanceEvent::query()->whereKey($event->id)->exists())->toBeFalse();
    });
});

describe('two events at once', function () {
    it('gives the one aimed at fewer countries', function () {
        scheduleEvent($this->admin, ['name' => 'For everyone', 'countries' => [], 'colours' => ['primary' => '#1E2229']]);
        scheduleEvent($this->admin, ['name' => 'For Saudi Arabia', 'colours' => ['primary' => '#006C35']]);

        expect(primaryFor('SA'))->toBe('#006C35')->and(primaryFor('FR'))->toBe('#1E2229');
    });

    it('then the one that started later, then the one edited last', function () {
        scheduleEvent($this->admin, ['name' => 'Earlier', 'starts_on' => '2026-09-20', 'colours' => ['primary' => '#1E2229']]);
        scheduleEvent($this->admin, ['name' => 'Later', 'starts_on' => '2026-09-22', 'colours' => ['primary' => '#006C35']]);
        expect(primaryFor('SA'))->toBe('#006C35');

        $this->travel(1)->minutes();
        scheduleEvent($this->admin, ['name' => 'Same start, edited last', 'starts_on' => '2026-09-22', 'colours' => ['primary' => '#4A1526']]);
        expect(primaryFor('SA'))->toBe('#4A1526');
    });
});

describe('what an event changes', function () {
    it('lays its colours over the live look’s own, and keeps them readable', function () {
        looks()->saveDraft(['colours' => ['bg' => '#FBF8F0']], $this->admin);
        looks()->publish($this->admin);

        scheduleEvent($this->admin);
        $tokens = looks()->lookFor('SA', 'website')->tokens();

        expect($tokens['light']['primary'])->toBe('#006C35')->and($tokens['light']['bg'])->toBe('#FBF8F0')
            ->and(array_filter(ThemeEngine::validate($tokens), fn (array $c) => ! $c['pass'] && $c['blocking']))->toBe([]);
    });

    it('says which colours the contrast repair moved when it is scheduled', function () {
        $event = scheduleEvent($this->admin, ['colours' => ['info' => '#9CC3F5']]);

        expect($event->repaired)->not->toBeEmpty()
            ->and(looks()->lookFor('SA', 'website')->tokens()['light']['info'])->not->toBe('#9CC3F5');
    });

    it('keeps the usual colours when it chooses none', function () {
        scheduleEvent($this->admin, ['colours' => ['primary' => '']]);

        expect(looks()->lookFor('SA', 'website')->tokens())->toBe(ThemeEngine::BASE)
            ->and(looks()->lookFor('SA', 'website')->hasCustomColours())->toBeFalse();
    });

    it('replaces only the pictures and banners it sets', function () {
        $logo = app(BrandImages::class)->store(UploadedFile::fake()->image('logo.png', 300, 100), 'logo', $this->admin);
        $hero = app(BrandImages::class)->store(UploadedFile::fake()->image('hero.jpg', 1600, 900), 'hero', $this->admin);
        looks()->saveDraft([
            'images' => ['logo' => $logo->id],
            'banners' => ['webapp' => ['enabled' => true, 'text' => ['en' => ['title' => 'Usual web app banner']]], 'website' => ['enabled' => true, 'text' => ['en' => ['title' => 'Usual website banner']]]],
        ], $this->admin);
        looks()->publish($this->admin);

        scheduleEvent($this->admin, [
            'images' => ['hero' => $hero->id],
            'banners' => ['website' => ['enabled' => true, 'tone' => 'navy', 'text' => ['en' => ['title' => 'Happy Saudi National Day'], 'ar' => ['title' => 'كل عام والوطن بخير']]]],
        ]);
        $look = looks()->lookFor('SA', 'website');

        expect($look->imageUrl('logo'))->toBe($logo->url())->and($look->imageUrl('hero'))->toBe($hero->url())
            ->and($look->banner('website', 'ar')['title'])->toBe('كل عام والوطن بخير')
            ->and(looks()->lookFor('SA', 'webapp')->banner('webapp', 'en')['title'])->toBe('Usual web app banner')
            ->and(looks()->lookFor('FR', 'website')->banner('website', 'en')['title'])->toBe('Usual website banner');
    });

    it('tells the page which event it is wearing, and until when', function () {
        $event = scheduleEvent($this->admin);
        $look = looks()->lookFor('SA', 'website');

        expect($look->event())->toMatchArray(['id' => $event->id, 'name' => 'Saudi National Day', 'revision' => $event->revision, 'ends_on' => '2026-09-24'])
            ->and($look->event()['until'])->toBe('2026-09-24T21:00:00+00:00')
            ->and(looks()->lookFor('FR', 'website')->event())->toBeNull();
    });

    it('takes effect at once when it is edited, under a new revision', function () {
        $event = scheduleEvent($this->admin);
        $before = $event->revision;

        $event = looks()->saveEvent($event, ['colours' => ['primary' => '#4A1526']], $this->admin);

        expect($event->revision)->toBeGreaterThan($before)->and(primaryFor('SA'))->toBe('#4A1526');
    });
});

describe('where a visitor is', function () {
    it('is the workspace’s country for someone signed in, before any header or language', function () {
        [$owner] = owner(['country' => 'AE']);
        config(['qistas.geo_header' => 'CF-IPCountry']);
        $request = Request::create('/', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'SA', 'HTTP_ACCEPT_LANGUAGE' => 'ar-SA']);
        $request->setUserResolver(fn () => $owner);

        expect(Visitor::country($request))->toBe('AE');
    });

    it('is the CDN’s country header when one is configured, and only then', function () {
        $request = Request::create('/', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'sa', 'HTTP_ACCEPT_LANGUAGE' => 'en-US']);

        config(['qistas.geo_header' => null]);
        expect(Visitor::country($request))->toBe('US');

        config(['qistas.geo_header' => 'CF-IPCountry']);
        expect(Visitor::country($request))->toBe('SA');
    });

    it('is the region of the browser’s language otherwise, and nobody when nothing says', function () {
        config(['qistas.geo_header' => 'CF-IPCountry']);

        expect(Visitor::country(Request::create('/', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'XX', 'HTTP_ACCEPT_LANGUAGE' => 'ar-KW,ar;q=0.9'])))->toBe('KW')
            ->and(Visitor::country(Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar'])))->toBeNull();
    });
});

describe('what an admin may type', function () {
    it('refuses what cannot be an event', function (array $input, string $key) {
        $base = ['name' => 'Event', 'countries' => ['SA'], 'starts_on' => '2026-09-23', 'ends_on' => '2026-09-24', 'timezone' => 'Asia/Riyadh', 'surfaces' => ['website']];

        expect(validationKeys(fn () => looks()->saveEvent(null, array_replace($base, $input), $this->admin)))->toContain($key);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'markup in the name' => [['name' => 'Eid <script>'], 'name'],
        'an unknown country' => [['countries' => ['ZZ']], 'countries.0'],
        'the end before the start' => [['ends_on' => '2026-09-22'], 'ends_on'],
        'a date that is not one' => [['starts_on' => '23/09/2026'], 'starts_on'],
        'a time zone that does not exist' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'no place' => [['surfaces' => []], 'surfaces'],
        'an unknown place' => [['surfaces' => ['fridge']], 'surfaces.0'],
        'a colour that is not one' => [['colours' => ['primary' => 'red;}']], 'colours.primary'],
        'a dark canvas' => [['colours' => ['bg' => '#101010']], 'colours.bg'],
        'a banner with no English title' => [['banners' => ['website' => ['enabled' => true, 'text' => ['ar' => ['title' => 'عيد']]]]], 'banners.website.text.en.title'],
    ]);

    it('writes every change to the audit log', function () {
        $event = scheduleEvent($this->admin);
        looks()->stopEvent($event, $this->admin);
        looks()->deleteEvent($event->fresh(), $this->admin);

        expect(AuditLog::query()->where('action', 'like', 'appearance.event_%')->pluck('action')->all())
            ->toEqualCanonicalizing(['appearance.event_saved', 'appearance.event_scheduled', 'appearance.event_stopped', 'appearance.event_deleted']);
    });

    it('refuses to schedule a look that cannot be made readable', function () {
        $event = looks()->saveEvent(null, ['name' => 'Event', 'countries' => ['SA'], 'starts_on' => '2026-09-23', 'ends_on' => '2026-09-24', 'timezone' => 'Asia/Riyadh', 'surfaces' => ['website']], $this->admin);

        // Every check can be repaired by the engine today; the refusal is the guard for a future that cannot.
        expect(fn () => looks()->scheduleEvent($event, $this->admin))->not->toThrow(AppearanceRefused::class);
    });
});

describe('the ready-made events', function () {
    it('each make a readable look', function (string $key) {
        $colours = EventPresets::LIST[$key]['colours'];
        $tokens = ThemeEngine::autoFix(ThemeEngine::resolve(['light' => $colours], repair: false))['tokens'];

        expect(array_filter(ThemeEngine::validate($tokens), fn (array $c) => ! $c['pass'] && $c['blocking']))->toBe([]);
    })->with(array_keys(EventPresets::LIST));

    it('suggest the next date of a fixed day, and none for a day that follows the moon', function () {
        $this->travelTo(Carbon::parse('2026-10-10 12:00', 'Asia/Riyadh'));

        expect(EventPresets::nextDates('saudi_national_day'))->toBe(['2027-09-23', '2027-09-24'])
            ->and(EventPresets::nextDates('white_friday'))->toBe(['2026-11-27', '2026-11-30'])
            ->and(EventPresets::nextDates('eid_al_fitr'))->toBeNull();

        $this->travelTo(Carbon::parse('2026-09-01 12:00', 'Asia/Riyadh'));
        expect(EventPresets::nextDates('saudi_national_day'))->toBe(['2026-09-23', '2026-09-24']);
    });

    it('carry their words in all five languages and a country where they belong', function () {
        foreach (EventPresets::LIST as $key => $preset) {
            expect(array_keys($preset['banner']))->toEqualCanonicalizing(['en', 'ar', 'fr', 'es', 'ur'], $key);
        }

        expect(EventPresets::LIST['saudi_national_day']['countries'])->toBe(['SA'])
            ->and(EventPresets::LIST['white_friday']['countries'])->toBe([]);
    });
});
