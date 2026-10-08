<?php

use App\Entitlements\Feature;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;

describe('the signed-in account', function () {
    it('tells the app who is signed in, where, and what the plan allows', function () {
        [$user, $tenant] = apiOwner(['name' => 'Al-Fares', 'currency' => 'SAR', 'country' => 'SA']);
        customerIn($tenant);
        customerIn($tenant);
        openContract($tenant);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonPath('data.user.two_factor', false)
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.tenant.name', 'Al-Fares')
            ->assertJsonPath('data.tenant.currency', 'SAR')
            ->assertJsonPath('data.tenant.role', 'owner')
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.entitlements.customers', ['type' => 'limit', 'status' => 'on', 'detail' => null, 'enabled' => true, 'limit' => 5, 'used' => 3, 'remaining' => 2, 'unlimited' => false])
            ->assertJsonPath('data.entitlements.active_contracts.used', 1)
            ->assertJsonPath('data.entitlements.export_csv.enabled', false);
    });

    it('never includes secrets', function () {
        apiOwner();

        $body = $this->getJson('/api/v1/me')->assertOk()->getContent();

        expect($body)->not->toContain('password')->not->toContain('two_factor_secret')->not->toContain('remember_token');
    });

    it('shows the role of whoever is signed in', function (string $role) {
        [, $tenant] = owner();
        apiMember($role, $tenant);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.role', $role);
    })->with(['manager', 'accountant', 'collector', 'viewer']);

    it('follows a change of plan at once', function () {
        [, $tenant] = apiOwner();
        $this->getJson('/api/v1/me')->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.entitlements.customers.unlimited', false);

        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

        $this->getJson('/api/v1/me')->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.entitlements.customers.unlimited', true)
            ->assertJsonPath('data.entitlements.export_csv.enabled', true);
    });

    it('answers in the language the app asks for', function () {
        apiOwner();

        $this->getJson('/api/v1/me', ['Accept-Language' => 'ar'])->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/v1/me', ['Accept-Language' => 'fr-CA,fr;q=0.9,en;q=0.8'])->assertHeader('Content-Language', 'fr');
        $this->getJson('/api/v1/me', ['Accept-Language' => 'de'])->assertHeader('Content-Language', 'en');
        $this->getJson('/api/v1/me?lang=es', ['Accept-Language' => 'ar'])->assertHeader('Content-Language', 'es');
    });

    it('falls back to the language saved on the account', function () {
        [$user] = apiOwner();
        $user->forceFill(['locale' => 'ur'])->save();

        // A client that says nothing about language (the test client otherwise sends en-US like a browser does).
        $this->getJson('/api/v1/me', ['Accept-Language' => ''])->assertHeader('Content-Language', 'ur');
    });
});

describe('the plans', function () {
    it('are public: what is on offer and what each plan includes', function () {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $free = collect($response->json('data'))->firstWhere('key', 'free');
        $pro = collect($response->json('data'))->firstWhere('key', 'pro');

        expect($free['is_free'])->toBeTrue()->and($free['monthly_price'])->toBe('0.00')
            ->and($free['features']['customers'])->toMatchArray(['enabled' => true, 'limit' => 5, 'type' => 'limit'])
            ->and($free['features']['export_csv']['enabled'])->toBeFalse()
            ->and($pro['is_free'])->toBeFalse()->and($pro['features']['customers']['limit'])->toBeNull()
            ->and($pro['features']['export_csv']['enabled'])->toBeTrue()
            ->and($pro['currency'])->toBe('USD');
    });

    it('show exactly what the admin chose', function () {
        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, true, 12);
        Plan::where('key', 'free')->sole()->setFeature(Feature::ExportCsv, true);

        $free = collect($this->getJson('/api/v1/plans')->json('data'))->firstWhere('key', 'free');

        expect($free['features']['customers']['limit'])->toBe(12)->and($free['features']['export_csv']['enabled'])->toBeTrue();
    });

    it('are described in the reader’s language', function () {
        $english = collect($this->getJson('/api/v1/plans')->json('data'))->firstWhere('key', 'free')['features']['customers']['summary'];
        $arabic = collect($this->getJson('/api/v1/plans', ['Accept-Language' => 'ar'])->json('data'))->firstWhere('key', 'free')['features']['customers']['summary'];

        expect($english)->toBe('Up to 5 customers')->and($arabic)->not->toBe($english);
    });

    it('list the cheapest first and include every feature', function () {
        $data = $this->getJson('/api/v1/plans')->json('data');

        expect(array_column($data, 'key'))->toBe(['free', 'pro'])
            ->and(array_keys($data[0]['features']))->toBe(array_map(fn (Feature $f) => $f->value, Feature::cases()));
    });

    it('do not include plans that are not public', function () {
        Plan::create(['name' => 'Secret', 'is_public' => false, 'sort_order' => 99]);

        expect(array_column($this->getJson('/api/v1/plans')->json('data'), 'key'))->not->toContain('secret');
    });

    it('are rate limited without a sign-in, per address', function () {
        foreach (range(1, 60) as $_) {
            $this->getJson('/api/v1/plans')->assertOk();
        }

        $this->getJson('/api/v1/plans')->assertTooManyRequests();
    });
});

it('answers an unknown address or method in the same JSON shape', function () {
    $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->postJson('/api/v1/plans')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('answers an unexpected failure without revealing what broke', function () {
    config(['app.debug' => false]);
    Route::middleware('api')->get('/api/v1/_boom', fn () => throw new RuntimeException('secret detail: db password is hunter2'));

    $response = $this->getJson('/api/v1/_boom')->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    expect($response->getContent())->not->toContain('secret detail')->not->toContain('hunter2');
});

it('lets a browser app on another address ask what is allowed', function () {
    $this->call('OPTIONS', '/api/v1/customers', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,idempotency-key,content-type',
    ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');
});
