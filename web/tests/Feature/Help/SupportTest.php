<?php

use App\Help\SupportSettings;
use App\Models\AppNotification;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Win Plan PP17: help inside the app. Someone writes in their language; the ticket carries the app version and platform,
| never a password. The app shows how to reach the team (WhatsApp with a support code naming the workspace and the app
| version) and the promise: a first reply within 24 hours, within 4 for a report of missing data. The Qistas team works
| the queue in /admin/support, missing data first; a reply reaches the person in their inbox and on their phone.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

/** @param  array<string, mixed>  $overrides */
function sendTicket(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/support/tickets', $overrides + [
        'category' => 'problem', 'message' => 'A payment I recorded yesterday is not on the contract.', 'app_version' => '1.16.0', 'platform' => 'android',
    ]);
}

function supportStaff(string $role = 'admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function ticketOf(Tenant $tenant): SupportTicket
{
    return SupportTicket::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->firstOrFail();
}

describe('writing to the team from the app', function () {
    it('files the message with the app version, the platform and the language, and never a password', function () {
        [$owner, $tenant] = apiOwner();

        sendTicket(['password' => 'hunter2-secret', 'token' => 'qst_abc'])->assertCreated()
            ->assertJsonPath('data.category', 'problem')->assertJsonPath('data.status', 'open')->assertJsonPath('data.app_version', '1.16.0');

        $ticket = ticketOf($tenant);
        expect($ticket->user_id)->toBe($owner->id)->and($ticket->app_version)->toBe('1.16.0')->and($ticket->platform)->toBe('android')
            ->and($ticket->language)->toBe('en')
            ->and((string) json_encode($ticket->getAttributes()))->not->toContain('hunter2-secret')->not->toContain('qst_abc');
    });

    it('refuses an empty message, an unknown kind of message and an unknown platform', function () {
        apiOwner();

        sendTicket(['message' => ' '])->assertStatus(422)->assertJsonValidationErrors('message', 'error.fields');
        sendTicket(['category' => 'gossip'])->assertStatus(422)->assertJsonValidationErrors('category', 'error.fields');
        sendTicket(['platform' => 'toaster'])->assertStatus(422)->assertJsonValidationErrors('platform', 'error.fields');

        expect(SupportTicket::withoutGlobalScopes()->count())->toBe(0);
    });

    it('lists the person’s own questions with the team’s replies, never anyone else’s', function () {
        [$owner, $tenant] = apiOwner();
        sendTicket(['message' => 'Mine'])->assertCreated();
        apiMember('collector', $tenant);
        sendTicket(['message' => 'The collector’s'])->assertCreated();
        apiOwner();
        sendTicket(['message' => 'Another business'])->assertCreated();

        Sanctum::actingAs($owner, ['app']);
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'Mine');
    });

    it('tells the app how to reach the team, with a code for the workspace and the promise', function () {
        [, $tenant] = apiOwner();
        $code = SupportSettings::code($tenant);

        $this->getJson('/api/v1/support')->assertOk()
            ->assertJsonPath('data.whatsapp', null)->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.reply_hours', 24)->assertJsonPath('data.missing_data_hours', 4);

        app(SupportSettings::class)->setWhatsapp('+966 50 123 4567', null);
        $this->getJson('/api/v1/support')->assertJsonPath('data.whatsapp', '966501234567');
        expect($code)->toMatch('/^Q-[0-9A-F]{6}$/');
    });
});

describe('the team’s queue', function () {
    it('shows reports of missing data first, with how long each has waited', function () {
        [, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
        sendTicket(['category' => 'question', 'message' => 'How do I add a partner?'])->assertCreated();
        $this->travel(2)->hours();
        sendTicket(['category' => 'missing_data', 'message' => 'Three customers are gone from my list.'])->assertCreated();
        $this->travel(1)->hours();

        $page = $this->actingAs(supportStaff())->get(route('admin.support.index'))->assertOk()
            ->assertSee('Al-Fares Electronics')->assertSee(SupportSettings::code($tenant));
        $html = $page->getContent();
        expect(strpos($html, 'Three customers are gone'))->toBeLessThan(strpos($html, 'How do I add a partner?'));
    });

    it('sends a reply to the person, records when the first one went, and keeps that time', function () {
        [$owner, $tenant] = apiOwner();
        sendTicket()->assertCreated();
        $ticket = ticketOf($tenant);
        $staff = supportStaff();

        $this->travel(3)->hours();
        $this->actingAs($staff)->post(route('admin.support.reply', $ticket), ['body' => 'Found it: it was recorded on contract C-0004. Moving it now.'])->assertRedirect();
        $first = ticketOf($tenant)->first_reply_at;
        $this->travel(1)->hours();
        $this->actingAs($staff)->post(route('admin.support.reply', $ticket), ['body' => 'Done.'])->assertRedirect();

        $fresh = ticketOf($tenant);
        expect($fresh->status)->toBe('answered')->and($fresh->first_reply_at->toDateTimeString())->toBe($first->toDateTimeString())
            ->and($first->toDateTimeString())->toBe('2026-10-11 12:00:00')
            ->and(AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->where('type', 'support.reply')->count())->toBe(2);

        Sanctum::actingAs($owner, ['app']);
        $this->getJson('/api/v1/support/tickets')->assertJsonCount(2, 'data.0.replies')->assertJsonPath('data.0.replies.0.body', 'Found it: it was recorded on contract C-0004. Moving it now.');
    });

    it('closes a ticket, and keeps a reply from going out on an empty body', function () {
        apiOwner();
        sendTicket()->assertCreated();
        $ticket = SupportTicket::withoutGlobalScopes()->sole();
        $staff = supportStaff();

        $this->actingAs($staff)->post(route('admin.support.reply', $ticket), ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($staff)->post(route('admin.support.close', $ticket))->assertRedirect();

        expect($ticket->fresh()->status)->toBe('closed')->and($ticket->fresh()->closed_at)->not->toBeNull()
            ->and($ticket->replies()->count())->toBe(0);
    });

    it('is for platform staff only; the WhatsApp number is set by a super admin', function () {
        [$owner] = apiOwner();

        $this->actingAs($owner)->get(route('admin.support.index'))->assertNotFound();
        $this->actingAs(supportStaff('admin'))->put(route('admin.support.settings'), ['whatsapp' => '+966501234567'])->assertForbidden();
        $this->actingAs(supportStaff('super_admin'))->put(route('admin.support.settings'), ['whatsapp' => 'not a number'])->assertSessionHasErrors('whatsapp');
        $this->actingAs(supportStaff('super_admin'))->put(route('admin.support.settings'), ['whatsapp' => '+966 50 123 4567'])->assertRedirect();

        expect(app(SupportSettings::class)->whatsapp())->toBe('966501234567');
    });
});
