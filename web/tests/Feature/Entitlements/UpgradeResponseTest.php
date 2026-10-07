<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\LimitReached;
use App\Entitlements\UsageMeters;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

it('answers an API client at a limit with 402 and the documented error body', function () {
    Route::middleware('api')->get('/api/_test/limit', fn () => throw new LimitReached(Feature::Customers, limit: 5, used: 5));

    $this->getJson('/api/_test/limit')
        ->assertStatus(402)
        ->assertExactJson(['error' => [
            'code' => 'limit_reached',
            'message' => __('You have reached the limit of :limit :unit on your plan.', ['limit' => 5, 'unit' => Feature::Customers->unit()]),
            'feature' => 'customers',
            'limit' => 5,
            'used' => 5,
            'upgrade_url' => url('/app/billing'),
        ]]);
});

it('answers an API client asking for a locked feature with 402 too', function () {
    Route::middleware('api')->get('/api/_test/locked', fn () => throw new FeatureLocked(Feature::ExportCsv));

    $this->getJson('/api/_test/locked')
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_locked')
        ->assertJsonPath('error.feature', 'export_csv')
        ->assertJsonPath('error.limit', null)
        ->assertJsonPath('error.upgrade_url', url('/app/billing'));
});

it('sends a browser back with what to show in the upgrade sheet', function () {
    Route::middleware('web')->post('/_test/limit', fn () => throw new LimitReached(Feature::Customers, limit: 5, used: 5));

    $this->from('/somewhere')->post('/_test/limit')
        ->assertRedirect('/somewhere')
        ->assertSessionHas('upgrade', fn (array $upgrade) => $upgrade['code'] === 'limit_reached'
            && $upgrade['feature'] === 'customers'
            && $upgrade['limit'] === 5
            && $upgrade['used'] === 5
            && $upgrade['upgrade_url'] === url('/app/billing'));
});

describe('the feature middleware', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth', 'tenant', 'feature:export_csv'])->get('/_test/export', fn () => 'csv');
        Route::middleware(['api', 'auth:web', 'tenant', 'feature:export_csv'])->get('/api/_test/export', fn () => 'csv');
        app(UsageMeters::class)->register(Feature::Customers, fn () => 0);
    });

    function memberOf(string $plan): User
    {
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo(Plan::where('key', $plan)->sole());
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'owner']);

        return $user;
    }

    it('lets a Pro workspace through', function () {
        $this->actingAs(memberOf('pro'))->get('/_test/export')->assertOk()->assertSee('csv');
    });

    it('turns a Free workspace away with the upgrade sheet', function () {
        $this->actingAs(memberOf('free'))->from('/app')->get('/_test/export')
            ->assertRedirect('/app')
            ->assertSessionHas('upgrade.code', 'feature_locked')
            ->assertSessionHas('upgrade.feature', 'export_csv');
    });

    it('turns a Free API client away with 402', function () {
        $this->actingAs(memberOf('free'))->getJson('/api/_test/export')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'feature_locked');
    });

    it('follows the admin matrix at once', function () {
        $user = memberOf('free');
        $this->actingAs($user)->getJson('/api/_test/export')->assertStatus(402);

        Plan::where('key', 'free')->sole()->setFeature(Feature::ExportCsv, enabled: true);

        $this->actingAs($user)->getJson('/api/_test/export')->assertOk();
    });
});
