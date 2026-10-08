<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

describe('the dashboard', function () {
    it('needs a token', function () {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    });

    it('gives the headline figures as exact amounts, and who pays today', function () {
        $this->travelTo('2026-10-07 12:00:00');
        [, $tenant] = apiOwner(['currency' => 'SAR']);
        $late = openContract($tenant, ['first_due_date' => '2026-09-01']);
        openContract($tenant, ['first_due_date' => '2026-10-07']);
        app(RecordPayment::class)->handle($late, '100.00', 'cash');

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        expect($response->json('data.currency'))->toBe('SAR')
            ->and($response->json('data.outstanding'))->toBe('500.00')
            ->and($response->json('data.overdue'))->toBe('100.00')
            ->and($response->json('data.collected_this_month'))->toBe('100.00')
            ->and($response->json('data.active_customers'))->toBe(2)
            ->and($response->json('data.due_today'))->toHaveCount(1)
            ->and($response->json('data.due_today.0'))->toHaveKeys(['contract_id', 'contract_reference', 'customer_name', 'amount_due', 'due_date']);
    });

    it('reads only this workspace', function () {
        apiOwner();
        openContract(Tenant::factory()->create());

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.outstanding', '0.00')->assertJsonPath('data.active_customers', 0);
    });
});

describe('API access tokens', function () {
    it('are made on purpose, shown once, and listed without their secret', function () {
        [$user] = apiOwner();

        $created = $this->postJson('/api/v1/tokens', ['name' => 'Accounting export'])->assertCreated();
        $plain = $created->json('data.token');

        expect($plain)->toMatch('/^\d+\|qst_\w+$/')->and($created->json('data.name'))->toBe('Accounting export')
            ->and(PersonalAccessToken::findToken($plain)->abilities)->toBe(['integration']);

        $list = $this->getJson('/api/v1/tokens')->assertOk();
        expect($list->json('data'))->toHaveCount(1)->and($list->getContent())->not->toContain($plain)->not->toContain('token":');
        expect($list->json('data.0'))->toHaveKeys(['id', 'name', 'created_at', 'last_used_at', 'expires_at']);
        expect($user->tokens()->count())->toBe(1);
    });

    it('do not include the phone’s own sign-in', function () {
        [$user] = apiOwner();
        $user->createToken('Pixel 9', ['app']);

        $this->getJson('/api/v1/tokens')->assertOk()->assertJsonCount(0, 'data');
    });

    it('are not used up by the phone’s own sign-in', function () {
        [$user] = apiOwner();
        $user->createToken('Pixel 9', ['app']);
        $this->getJson('/api/v1/me')->assertJsonPath('data.entitlements.api_tokens.used', 0);

        $this->postJson('/api/v1/tokens', ['name' => 'first'])->assertCreated();

        $this->getJson('/api/v1/me')->assertJsonPath('data.entitlements.api_tokens.used', 1);
    });

    it('work for the data, but cannot make more tokens', function () {
        [$user, $tenant] = apiOwner();
        $customer = customerIn($tenant);
        $plain = $user->createToken('export', ['integration'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/v1/customers')->assertOk()->assertJsonPath('data.0.id', $customer->id);
        $this->withToken($plain)->postJson('/api/v1/tokens', ['name' => 'another'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->withToken($plain)->postJson('/api/v1/auth/logout-all')->assertNoContent();
    });

    it('are limited by the plan: one on Free, with a 402 after that', function () {
        [, $tenant] = apiOwner();
        $this->postJson('/api/v1/tokens', ['name' => 'first'])->assertCreated();

        $this->postJson('/api/v1/tokens', ['name' => 'second'])->assertStatus(402)
            ->assertJsonPath('error.code', 'limit_reached')->assertJsonPath('error.feature', 'api_tokens')
            ->assertJsonPath('error.limit', 1)->assertJsonPath('error.used', 1);
        $this->getJson('/api/v1/me')->assertJsonPath('data.entitlements.api_tokens.used', 1);
    });

    it('are unlimited on Pro, and a plan that does not include them says so', function () {
        [, $tenant] = apiOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/tokens', ['name' => "token {$i}"])->assertCreated();
        }

        $tenant->subscribeTo(Plan::where('key', 'free')->sole());
        Plan::where('key', 'free')->sole()->setFeature(Feature::ApiTokens, false);

        $this->postJson('/api/v1/tokens', ['name' => 'four'])->assertStatus(402)->assertJsonPath('error.code', 'feature_locked');
    });

    it('can be revoked, and then stop working', function () {
        [$user] = apiOwner();
        $plain = $this->postJson('/api/v1/tokens', ['name' => 'export'])->json('data.token');
        $id = $this->getJson('/api/v1/tokens')->json('data.0.id');

        $this->deleteJson("/api/v1/tokens/{$id}")->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        expect($user->tokens()->count())->toBe(0);
    });

    it('cannot be revoked by someone else', function () {
        [$user] = apiOwner();
        $this->postJson('/api/v1/tokens', ['name' => 'export'])->assertCreated();
        $id = $user->tokens()->sole()->id;
        [$stranger] = makeAccount();
        Sanctum::actingAs($stranger, ['app']);

        $this->deleteJson("/api/v1/tokens/{$id}")->assertNotFound();
        expect($user->tokens()->count())->toBe(1);
    });

    it('need a name', function () {
        apiOwner();

        $this->postJson('/api/v1/tokens', [])->assertUnprocessable()->assertJsonValidationErrors('name', 'error.fields');
        $this->postJson('/api/v1/tokens', ['name' => str_repeat('n', 101)])->assertUnprocessable();
    });
});
