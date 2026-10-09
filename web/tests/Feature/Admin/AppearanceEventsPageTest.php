<?php

use App\Models\AppearanceEvent;
use App\Models\User;
use App\Theme\Appearance;
use App\Theme\ThemeEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/*
| The events studio: on the Appearance page, the calendar of event themes (a timeline, each event's state, overlaps)
| and ready-made events to start from; an editor per event with its countries, days, places, colours, pictures and
| banners, a live preview, and Save draft / Schedule / Stop / Delete; and "Preview as" a country on a date.
*/

function eventsStaff(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** The editor's fields as a browser sends them. */
function eventForm(array $changes = []): array
{
    return array_replace([
        'name' => 'Saudi National Day',
        'countries' => ['SA'],
        'starts_on' => '2026-09-23',
        'ends_on' => '2026-09-24',
        'timezone' => 'Asia/Riyadh',
        'surfaces' => ['website', 'webapp', 'mobile'],
        'colours' => ['primary' => '#006C35', 'accent' => '', 'info' => '', 'bg' => ''],
        'banners' => ['website' => ['enabled' => '1', 'tone' => 'navy', 'dismissible' => '1', 'image' => '0', 'cta_url' => '', 'text' => ['en' => ['title' => 'Happy Saudi National Day', 'message' => '', 'cta_label' => '']]]],
    ], $changes);
}

function scheduledEvent(User $admin, array $changes = []): AppearanceEvent
{
    $input = eventForm($changes);
    $input['banners']['website']['enabled'] = true;

    return app(Appearance::class)->scheduleEvent(app(Appearance::class)->saveEvent(null, $input, $admin), $admin);
}

beforeEach(function () {
    // The clock first, then the people: an admin made "later" than the test's today is not a world that exists.
    $this->travelTo(Carbon::parse('2026-09-21 10:00:00', 'Asia/Riyadh'));
    $this->admin = eventsStaff();
});

describe('the calendar on the Appearance page', function () {
    it('lists each event with its days, its countries and where it stands', function () {
        scheduledEvent($this->admin);
        scheduledEvent($this->admin, ['name' => 'Already over', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-02']);
        app(Appearance::class)->saveEvent(null, eventForm(['name' => 'Still a draft']), $this->admin);

        preg_match('/<section id="events".*?<\/section>/s', $this->actingAs($this->admin)->get('/admin/appearance')->assertOk()->getContent(), $section);

        expect($section[0])->toContain('Saudi National Day')->toContain('Starts in 2 days')->toContain('Saudi Arabia')
            ->toContain('Already over')->toContain('Ended')
            ->toContain('Still a draft')->toContain('Draft');
    });

    it('draws a twelve-month timeline with today and a bar for each event to come', function () {
        $event = scheduledEvent($this->admin);
        $over = scheduledEvent($this->admin, ['name' => 'Already over', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-02']);

        preg_match('/<svg class="event-timeline".*?<\/svg>/s', $this->actingAs($this->admin)->get('/admin/appearance')->getContent(), $svg);

        expect($svg[0])->toContain('data-today')->toContain('data-event="'.$event->id.'"')->toContain('fill="#006C35"')
            // This month's first days are on the timeline, but an event already over is not drawn there.
            ->not->toContain('data-event="'.$over->id.'"');
    });

    it('offers the ready-made events with their next date', function () {
        $html = $this->actingAs($this->admin)->get('/admin/appearance')->getContent();

        expect($html)->toContain(route('admin.appearance.events.create', ['preset' => 'saudi_national_day']))
            ->toContain('UAE National Day')->toContain('2 Dec 2026')
            ->toContain('Ramadan')->toContain('Set the dates each year');
    });

    it('warns when two events meet, and names the one people will see', function () {
        scheduledEvent($this->admin, ['name' => 'For everyone', 'countries' => [], 'colours' => ['primary' => '#1E2229']]);
        scheduledEvent($this->admin, ['name' => 'For Saudi Arabia']);

        preg_match('/<section id="events".*?<\/section>/s', $this->actingAs($this->admin)->get('/admin/appearance')->getContent(), $section);

        expect($section[0])->toContain('Overlaps with')->toContain('In Saudi Arabia, people see “For Saudi Arabia”.');
    });
});

describe('starting an event', function () {
    it('fills a new event from a ready-made one', function () {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'Asia/Riyadh'));
        $html = $this->actingAs($this->admin)->get('/admin/appearance/events/new?preset=saudi_national_day')->assertOk()->getContent();

        expect($html)->toContain('value="Saudi National Day"')
            ->toMatch('/name="countries\[\]" value="SA"[^>]*checked/')
            ->toContain('name="starts_on" value="2027-09-23"')->toContain('name="ends_on" value="2027-09-24"')
            ->toContain('name="colours[primary]" value="#006C35"')
            ->toContain('value="كل عام والوطن بخير"');
    });

    it('saves a draft, which nobody sees yet', function () {
        $this->actingAs($this->admin)->post('/admin/appearance/events', eventForm() + ['action' => 'save'])
            ->assertRedirect()->assertSessionHas('status', 'Saved as a draft. Nobody sees it until you schedule it.');

        $event = AppearanceEvent::query()->sole();
        expect($event->status)->toBe('draft')->and($event->countries)->toBe(['SA'])->and($event->pins())->toBe(['light' => ['primary' => '#006C35']]);
    });

    it('schedules at once when asked, and says when and where it will show', function () {
        $this->actingAs($this->admin)->post('/admin/appearance/events', eventForm() + ['action' => 'schedule'])
            ->assertRedirect()->assertSessionHas('status', 'Scheduled: Saudi National Day shows from 23 Sep 2026 to 24 Sep 2026 in Saudi Arabia.');

        expect(AppearanceEvent::query()->sole()->status)->toBe('scheduled');
    });

    it('says why beside the field when something is wrong, and keeps what was typed', function () {
        $sent = eventForm(['ends_on' => '2026-09-20', 'name' => 'Kept name']) + ['action' => 'save'];

        // Asking the test session for its errors would use them up before the page is drawn, so the page is read first.
        $this->actingAs($this->admin)->from('/admin/appearance/events/new')->post('/admin/appearance/events', $sent)->assertRedirect('/admin/appearance/events/new');
        $html = $this->actingAs($this->admin)->get('/admin/appearance/events/new')->getContent();

        expect($html)->toMatch('/<p class="field-error">The last day must be on or after the first day\.<\/p>/')->toContain('value="Kept name"')
            ->and(AppearanceEvent::query()->count())->toBe(0);

        $this->actingAs($this->admin)->post('/admin/appearance/events', $sent)->assertSessionHasErrors('ends_on');
    });
});

describe('changing an event', function () {
    it('saves changes to a scheduled event, which visitors see at once', function () {
        $event = scheduledEvent($this->admin);

        $this->actingAs($this->admin)->put('/admin/appearance/events/'.$event->id, eventForm(['colours' => ['primary' => '#4A1526']]) + ['action' => 'save'])
            ->assertRedirect(route('admin.appearance.events.edit', $event))->assertSessionHas('status', 'Saved. Visitors in its countries see the change at once.');

        expect($event->fresh()->status)->toBe('scheduled')->and($event->fresh()->pins()['light']['primary'])->toBe('#4A1526');
    });

    it('stops an event at once and keeps it as a draft', function () {
        $event = scheduledEvent($this->admin);

        $this->actingAs($this->admin)->post('/admin/appearance/events/'.$event->id.'/stop')
            ->assertRedirect()->assertSessionHas('status', 'Stopped. Saudi National Day is a draft again; nobody sees it.');

        expect($event->fresh()->status)->toBe('draft');
    });

    it('deletes an event', function () {
        $event = scheduledEvent($this->admin);

        $this->actingAs($this->admin)->delete('/admin/appearance/events/'.$event->id)
            ->assertRedirect(route('admin.appearance.index').'#events')->assertSessionHas('status', 'Saudi National Day was deleted.');

        expect(AppearanceEvent::query()->count())->toBe(0);
    });

    it('shows the editor with the event as it is, and its preview in its colours', function () {
        $event = scheduledEvent($this->admin);
        $html = $this->actingAs($this->admin)->get('/admin/appearance/events/'.$event->id)->assertOk()->getContent();

        expect($html)->toContain('value="Saudi National Day"')->toContain('--q-primary: #006C35;')->toContain('data-event-editor');
    });

    it('takes a picture for the event, kept with it when saved', function () {
        $response = $this->actingAs($this->admin)->postJson('/admin/appearance/events/pictures/hero', ['file' => UploadedFile::fake()->image('hero.jpg', 1600, 900)])->assertCreated();
        $id = $response->json('data.id');

        $this->actingAs($this->admin)->post('/admin/appearance/events', eventForm(['images' => ['hero' => $id]]) + ['action' => 'save'])->assertRedirect();

        expect(AppearanceEvent::query()->sole()->images())->toBe(['hero' => $id]);
    });
});

describe('preview as', function () {
    it('answers what a visitor from a country sees on a date', function () {
        scheduledEvent($this->admin);

        $this->actingAs($this->admin)->getJson('/admin/appearance/look?country=SA&date=2026-09-23&surface=website')->assertOk()
            ->assertJsonPath('data.tokens.light.primary', '#006C35')->assertJsonPath('data.event.name', 'Saudi National Day')
            ->assertJsonPath('data.banner.title', 'Happy Saudi National Day');

        $this->actingAs($this->admin)->getJson('/admin/appearance/look?country=FR&date=2026-09-23&surface=website')->assertOk()
            ->assertJsonPath('data.event', null)->assertJsonPath('data.tokens', ThemeEngine::BASE);

        $this->actingAs($this->admin)->getJson('/admin/appearance/look?country=SA&date=tomorrow&surface=website')->assertUnprocessable();
    });
});

describe('who may use it', function () {
    it('lets other staff look at the calendar and the events, but not change them', function () {
        $event = scheduledEvent($this->admin);
        $viewer = eventsStaff('admin');

        expect($this->actingAs($viewer)->get('/admin/appearance')->getContent())->not->toContain(route('admin.appearance.events.create'))
            ->and($this->actingAs($viewer)->get('/admin/appearance/events/'.$event->id)->assertOk()->getContent())->toMatch('/<fieldset[^>]*class="studio-fields"[^>]*disabled/');

        $this->actingAs($viewer)->get('/admin/appearance/events/new')->assertForbidden();
        $this->actingAs($viewer)->post('/admin/appearance/events', eventForm() + ['action' => 'save'])->assertForbidden();
        $this->actingAs($viewer)->put('/admin/appearance/events/'.$event->id, eventForm())->assertForbidden();
        $this->actingAs($viewer)->post('/admin/appearance/events/'.$event->id.'/stop')->assertForbidden();
        $this->actingAs($viewer)->delete('/admin/appearance/events/'.$event->id)->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/appearance/events/pictures/hero', ['file' => UploadedFile::fake()->image('h.jpg')])->assertForbidden();
    });

    it('is not for customers', function () {
        [$owner] = owner();

        $this->actingAs($owner)->get('/admin/appearance/events/new')->assertNotFound();
        $this->actingAs($owner)->getJson('/admin/appearance/look?country=SA&date=2026-09-23&surface=website')->assertNotFound();
    });
});
