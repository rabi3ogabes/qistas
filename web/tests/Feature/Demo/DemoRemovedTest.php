<?php

use App\Models\Tenant;
use App\Models\User;
use App\Reports\PlatformOverview;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;

/*
| Go-live basics, section 6: the throw-away public demo ("Try the demo", a fresh account per press) is gone from the
| website, the API, the admin area and the scheduler. What stays (D7) is the container's own demo mode (config
| qistas.demo: the banner and the credentials on the sign-in page) and the developer seeder, which never runs in
| production.
*/

/** Platform staff who may open the admin area. */
function demoRemovedAdmin(): User
{
    return User::factory()->create([
        'platform_role' => 'super_admin',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
}

/** The old switches, both on: neither may bring anything of the public demo back. */
function demoRemovedSwitchesOn(): void
{
    config(['qistas.demo_login.enabled' => true, 'qistas.demo' => true]);
}

describe('the public demo is gone', function () {
    it('makes no throw-away account on the website, whatever the old switches say', function (string $address) {
        demoRemovedSwitchesOn();

        // No such address: not found, or "method not allowed" because the catch-all page only answers GET.
        expect($this->post($address)->status())->toBeIn([404, 405]);
        $this->get($address)->assertNotFound();
        expect(User::count())->toBe(0);
    })->with(['/demo/admin', '/demo/user', '/demo-leave']);

    it('has no demo in the API', function () {
        demoRemovedSwitchesOn();

        // An app that asks first is told there is nothing here (old apps read that as "no demo" and never press).
        $this->getJson('/api/v1/demo')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        // A press: no such address. The site's catch-all page answers GET for any path, so a POST may hear 405.
        expect($this->postJson('/api/v1/demo/admin', ['device_name' => 'Pixel'])->status())->toBeIn([404, 405])
            ->and($this->postJson('/api/v1/demo/user')->status())->toBeIn([404, 405])
            ->and(User::count())->toBe(0);
    });

    it('offers no demo buttons on the sign-in page', function () {
        demoRemovedSwitchesOn();

        $this->get('/login')->assertOk()
            ->assertDontSee('Just looking around?')
            ->assertDontSee('demo-choices', false)
            ->assertDontSee('Enter as admin')
            ->assertDontSee('Enter as user');
    });

    it('has no throw-away rate limits and no setting of its own', function () {
        expect(RateLimiter::limiter('demo'))->toBeNull()
            ->and(RateLimiter::limiter('api-demo'))->toBeNull()
            ->and(config('qistas.demo_login'))->toBeNull();
    });

    it('no longer tells the app whether a workspace is a throw-away one', function () {
        [$user] = apiOwner(['is_demo' => true]);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.tenant.is_test', false)
            ->assertJsonMissingPath('data.tenant.is_demo');
    });

    it('shows a leftover demo account no way out of the demo, and its own address', function () {
        [$user, $tenant] = owner(['is_demo' => true]);
        $user->forceFill(['demo_expires_at' => now()->addHour()])->save();

        $this->actingAs($user)->get('/app')->assertOk()
            ->assertDontSee('Create my free account')
            ->assertDontSee('/demo-leave', false)
            ->assertSee($user->email);
    });

    it('has no clean-up command and nothing about the demo in the scheduler', function () {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        expect(array_key_exists('qistas:prune-demo', Artisan::all()))->toBeFalse()
            ->and($commands->filter(fn (string $command) => str_contains($command, 'demo'))->all())->toBe([])
            // The scheduler itself is still loaded: only the demo line went.
            ->and($commands->contains(fn (string $command) => str_contains($command, 'qistas:purge-deleted-accounts')))->toBeTrue();
    });
});

describe('the admin area', function () {
    it('has no demo check and no button to clear demos', function () {
        demoRemovedSwitchesOn();

        expect(collect(app(PlatformOverview::class)->health())->firstWhere('key', 'demo'))->toBeNull();

        $this->actingAs(demoRemovedAdmin())->get('/admin')->assertOk()
            ->assertSee('Is everything set up?')
            ->assertDontSee('Demo sign-in')
            ->assertDontSee('Clear expired demos')
            ->assertDontSee('/admin/demo/prune', false);
    });

    it('has no address that clears demos', function () {
        $admin = demoRemovedAdmin();

        expect($this->actingAs($admin)->post('/admin/demo/prune')->status())->toBeIn([404, 405]);
    });
});

describe('what stays on purpose', function () {
    it('keeps the container demo mode: the banner and the credentials on the sign-in page', function () {
        config(['qistas.demo' => true]);

        $this->get('/login')->assertOk()
            ->assertSee('This is a demo')
            ->assertSee('demo-login', false)
            ->assertSee(DemoSeeder::EMAIL)
            ->assertSee(DemoSeeder::PASSWORD)
            ->assertDontSee('Just looking around?');
    });

    it('still runs the developer seeder locally', function () {
        $this->seed(DemoSeeder::class);

        $owner = User::where('email', DemoSeeder::EMAIL)->sole();

        expect($owner->tenants()->count())->toBe(1)
            ->and(Tenant::query()->where('owner_user_id', $owner->id)->sole()->is_demo)->toBeFalse();
    });

    it('still refuses to seed the demo business in production', function () {
        app()->detectEnvironment(fn () => 'production');

        // Called directly: `db:seed` would first ask "are you sure?" in production.
        expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class, 'local development only');
        expect(User::where('email', DemoSeeder::EMAIL)->exists())->toBeFalse();
    });
});
