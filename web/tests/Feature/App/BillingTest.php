<?php

use App\Models\Plan;

it('opens from the “Upgrade your plan” button instead of a dead end', function () {
    [$owner] = owner();

    $page = $this->actingAs($owner)->get('/app')->assertOk()->assertSee('/app/billing', false);

    $this->actingAs($owner)->get('/app/billing')->assertOk()->assertSee('Plan and billing');
});

it('shows the plan, how much of it is used, and what each plan includes', function () {
    [$owner] = owner();

    $this->actingAs($owner)->get('/app/billing')
        ->assertOk()
        ->assertSee('Your plan: Free', false)
        ->assertSee('0 of 5')
        ->assertSee('What each plan includes')
        ->assertSee('Pro');
});

it('says honestly how Pro is turned on while online payment is not', function () {
    [$owner, $tenant] = owner();

    $this->actingAs($owner)->get('/app/billing')
        ->assertSee('Move to Pro')
        ->assertSee('Online payment is not switched on yet')
        ->assertSee($tenant->name);
});

it('offers a ready-made e-mail to ask for Pro when a support address is set', function () {
    config(['qistas.support_email' => 'help@qistas.example']);
    [$owner] = owner();

    $this->actingAs($owner)->get('/app/billing')->assertSee('mailto:help@qistas.example', false);
});

it('does not offer an upgrade to a workspace that is already on Pro', function () {
    [$owner, $tenant] = owner();
    $tenant->subscribeTo(Plan::where('key', 'pro')->firstOrFail());

    $this->actingAs($owner)->get('/app/billing')
        ->assertOk()
        ->assertSee('Your plan: Pro', false)
        ->assertDontSee('Move to Pro');
});

it('is for signed-in members only, and only of their own workspace', function () {
    $this->get('/app/billing')->assertRedirect('/login');
});
