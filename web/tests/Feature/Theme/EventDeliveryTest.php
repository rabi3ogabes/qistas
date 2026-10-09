<?php

use App\Models\AppearanceEvent;
use App\Models\User;
use App\Theme\Appearance;
use App\Theme\BrandImages;
use App\Theme\ThemeEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/*
| An event reaches the people it is meant for: the website and the web app link its stylesheet and show its banner and
| pictures to a visitor from its countries, on its days; the Android app gets it through the API. Everyone else, the
| admin console always, keeps the usual look.
*/

function deliveryEventAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function nationalDay(User $admin, array $changes = []): AppearanceEvent
{
    $event = app(Appearance::class)->saveEvent(null, array_replace([
        'name' => 'Saudi National Day', 'countries' => ['SA'], 'starts_on' => '2026-09-23', 'ends_on' => '2026-09-24',
        'timezone' => 'Asia/Riyadh', 'surfaces' => ['website', 'webapp', 'mobile'],
        'colours' => ['primary' => '#006C35'],
        'banners' => [
            'website' => ['enabled' => true, 'tone' => 'navy', 'text' => ['en' => ['title' => 'Happy Saudi National Day'], 'ar' => ['title' => 'كل عام والوطن بخير']]],
            'webapp' => ['enabled' => true, 'tone' => 'navy', 'text' => ['en' => ['title' => 'National Day in the app']]],
            'mobile' => ['enabled' => true, 'tone' => 'navy', 'text' => ['en' => ['title' => 'National Day on the phone'], 'ar' => ['title' => 'اليوم الوطني على الجوال']]],
        ],
    ], $changes), $admin);

    return app(Appearance::class)->scheduleEvent($event, $admin);
}

function cssLink(AppearanceEvent $event, int $version = 0): string
{
    return 'href="'.e(route('theme.css', ['v' => $version, 'e' => $event->id.'.'.$event->revision])).'"';
}

beforeEach(function () {
    $this->admin = deliveryEventAdmin();
    $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Asia/Riyadh'));
});

describe('on the website', function () {
    it('dresses the pages of a visitor from its country, and only theirs', function () {
        $event = nationalDay($this->admin);

        $saudi = $this->withHeader('Accept-Language', 'ar-SA,ar;q=0.9')->get('/')->assertOk()->getContent();
        expect($saudi)->toContain(cssLink($event))->toContain('كل عام والوطن بخير');

        $french = $this->withHeader('Accept-Language', 'fr-FR')->get('/?lang=en')->assertOk()->getContent();
        expect($french)->not->toContain('&amp;e=')->not->toContain('Happy Saudi National Day');
    });

    it('shows its hero picture on the home page', function () {
        $hero = app(BrandImages::class)->store(UploadedFile::fake()->image('hero.jpg', 1600, 900), 'hero', $this->admin);
        nationalDay($this->admin, ['images' => ['hero' => $hero->id]]);

        expect($this->withHeader('Accept-Language', 'en-SA')->get('/')->getContent())->toContain('src="'.$hero->url().'"')
            ->and($this->withHeader('Accept-Language', 'en-GB')->get('/')->getContent())->not->toContain($hero->url());
    });

    it('is gone the day after its last day', function () {
        nationalDay($this->admin);
        $this->travelTo(Carbon::parse('2026-09-25 00:00:01', 'Asia/Riyadh'));

        expect($this->withHeader('Accept-Language', 'ar-SA')->get('/')->getContent())->not->toContain('كل عام والوطن بخير')->not->toContain('&amp;e=');
    });
});

describe('in the web app', function () {
    it('dresses a workspace in its country, whatever language its browser speaks', function () {
        $event = nationalDay($this->admin);
        [$saudi] = owner(['country' => 'SA']);
        [$french] = owner(['country' => 'FR']);

        $html = $this->actingAs($saudi)->withHeader('Accept-Language', 'en-US')->get('/app')->assertOk()->getContent();
        expect($html)->toContain(cssLink($event))->toContain('National Day in the app');

        expect($this->actingAs($french)->withHeader('Accept-Language', 'ar-SA')->get('/app')->getContent())->not->toContain('National Day in the app')->not->toContain('&amp;e=');
    });

    it('leaves the web app alone when the event is for the website only', function () {
        nationalDay($this->admin, ['surfaces' => ['website']]);
        [$saudi] = owner(['country' => 'SA']);

        expect($this->actingAs($saudi)->get('/app')->getContent())->not->toContain('&amp;e=')->not->toContain('National Day in the app');
    });

    it('never dresses the admin console', function () {
        nationalDay($this->admin, ['countries' => []]);

        expect($this->actingAs($this->admin)->get('/admin')->getContent())->not->toContain('/theme.css');
    });
});

describe('the stylesheet of an event', function () {
    it('holds the event’s palette and is kept for a year at its own address', function () {
        $event = nationalDay($this->admin);
        $tokens = app(Appearance::class)->lookFor('SA', 'website')->tokens();

        $response = $this->get('/theme.css?v=0&e='.$event->id.'.'.$event->revision)->assertOk();

        expect($response->getContent())->toContain('--q-primary: #006C35;')->toContain('--q-hero-to: '.$tokens['light']['heroTo'].';')
            ->and($response->headers->get('Cache-Control'))->toContain('immutable');
    });

    it('falls back to the usual look, briefly cached, for an unknown, stopped or old address', function () {
        $event = nationalDay($this->admin);
        $old = $event->id.'.'.$event->revision;
        app(Appearance::class)->stopEvent($event, $this->admin);

        foreach (['/theme.css?v=0&e='.$old, '/theme.css?v=0&e=nope.1', '/theme.css?v=0&e=01a00000-0000-7000-8000-000000000000.1'] as $url) {
            $response = $this->get($url)->assertOk();
            expect($response->getContent())->not->toContain('#006C35')
                ->and($response->headers->get('Cache-Control'))->not->toContain('immutable');
        }
    });
});

describe('the appearance API', function () {
    it('gives a Saudi workspace the event, with the usual look beside it for when it ends', function () {
        $event = nationalDay($this->admin);
        [, $workspace] = apiOwner(['country' => 'SA']);
        $base = app(Appearance::class)->live();

        $response = $this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/appearance')->assertOk();

        $response->assertJsonPath('data.tokens.light.primary', '#006C35')
            ->assertJsonPath('data.banner.title', 'اليوم الوطني على الجوال')
            ->assertJsonPath('data.event.id', $event->id)
            ->assertJsonPath('data.event.until', '2026-09-24T21:00:00+00:00')
            ->assertJsonPath('data.base.tokens', $base->tokens())
            ->assertJsonPath('data.base.banner', null);

        expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public')
            ->and($response->headers->get('Vary'))->toContain('Authorization')->toContain('Accept-Language');
    });

    it('gives everyone else the usual look and no event', function () {
        nationalDay($this->admin);
        apiOwner(['country' => 'FR']);

        $this->getJson('/api/v1/appearance')->assertOk()
            ->assertJsonPath('data.event', null)->assertJsonPath('data.base', null)
            ->assertJsonPath('data.tokens', ThemeEngine::BASE);
    });

    it('dresses the app before sign-in by the phone’s language region', function () {
        nationalDay($this->admin);

        $this->withHeaders(['Accept-Language' => 'ar-SA'])->getJson('/api/v1/appearance')->assertJsonPath('data.tokens.light.primary', '#006C35');
    });

    it('answers with a new tag when the event starts', function () {
        nationalDay($this->admin, ['starts_on' => '2026-09-24']);
        apiOwner(['country' => 'SA']);
        $before = $this->getJson('/api/v1/appearance')->assertJsonPath('data.event', null)->headers->get('ETag');

        $this->travelTo(Carbon::parse('2026-09-24 08:00:00', 'Asia/Riyadh'));

        $this->getJson('/api/v1/appearance', ['If-None-Match' => $before])->assertOk()->assertJsonPath('data.tokens.light.primary', '#006C35');
    });

    it('tells the app when a banner of the usual look ends', function () {
        app(Appearance::class)->saveDraft(['banners' => ['mobile' => ['enabled' => true, 'ends_on' => '2026-09-30', 'text' => ['en' => ['title' => 'Until the end of the month']]]]], $this->admin);
        app(Appearance::class)->publish($this->admin);

        $this->getJson('/api/v1/appearance')->assertJsonPath('data.banner.ends_on', '2026-09-30');
    });
});
