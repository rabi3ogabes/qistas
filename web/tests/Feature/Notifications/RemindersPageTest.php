<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\Tenant;
use App\Models\User;

/*
| Win Plan PP9 on the web: the remind-all checklist opens each customer's WhatsApp with the message ready, and owners
| and managers write the business's own wording there.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
    switchOn(Feature::InstalmentAlerts);
});

/** @return array{0: User, 1: Tenant} */
function remindersOnTheWeb(): array
{
    [$owner, $tenant] = owner(['name' => 'Al-Fares Electronics']);
    openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Amal', 'phone' => '0501112223'])->id, 'start_date' => '2026-09-11', 'first_due_date' => '2026-10-11']);
    openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Badr', 'phone' => '0502223334'])->id, 'start_date' => '2026-09-01', 'first_due_date' => '2026-10-01']);

    return [$owner, $tenant];
}

it('lists who pays today, each with a WhatsApp link that carries the message', function () {
    [$owner] = remindersOnTheWeb();

    $this->actingAs($owner)->get(route('app.reminders.index'))->assertOk()
        ->assertSee('Amal')->assertDontSee('Badr')
        ->assertSee('https://wa.me/966501112223?text='.rawurlencode('Hello Amal, a friendly reminder that '), false);
});

it('lists the late ones under their own tab', function () {
    [$owner] = remindersOnTheWeb();

    $this->actingAs($owner)->get(route('app.reminders.index', ['scope' => 'late']))->assertOk()
        ->assertSee('Badr')->assertSee('https://wa.me/966502223334?text='.rawurlencode('Hello Badr, '), false);
});

it('lets an owner write the business’s own wording, and a collector only read the list', function () {
    [$owner, $tenant] = remindersOnTheWeb();

    $this->actingAs($owner)->put(route('app.reminders.templates.update', 'reminder_due'), ['language' => 'en', 'body' => 'Dear :name, :amount please.'])
        ->assertRedirect()->assertSessionHas('status');
    $this->actingAs($owner)->get(route('app.reminders.index'))->assertSee(rawurlencode('Dear Amal, '), false);

    $collector = memberAs('collector', $tenant);
    $this->actingAs($collector)->get(route('app.reminders.index'))->assertOk()->assertDontSee('name="body"', false);
    $this->actingAs($collector)->put(route('app.reminders.templates.update', 'reminder_due'), ['language' => 'en', 'body' => 'Hi'])->assertForbidden();
});

it('offers the checklist from the dashboard when someone pays today', function () {
    [$owner] = remindersOnTheWeb();

    $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()->assertSee(route('app.reminders.index'), false);
});

it('is closed while the platform has it switched off', function () {
    app(FeatureControl::class)->setState(Feature::InstalmentAlerts, PlatformState::Off, null, null);
    [$owner] = remindersOnTheWeb();

    $this->actingAs($owner)->get(route('app.reminders.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()->assertDontSee(route('app.reminders.index'), false);
});
