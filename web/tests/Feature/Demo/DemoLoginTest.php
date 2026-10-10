<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Sandbox\DemoAccess;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/** Every demo account that exists. */
function demoUsers()
{
    return User::query()->whereNotNull('demo_expires_at')->get();
}

function demoCustomerCount(Tenant $tenant): int
{
    return app(CurrentTenant::class)->use($tenant, fn () => Customer::count());
}

describe('while demo sign-in is off (the default)', function () {
    beforeEach(fn () => config(['qistas.demo_login.enabled' => false, 'qistas.demo' => false]));

    it('shows no demo buttons and makes no accounts', function () {
        $this->get('/login')->assertOk()->assertDontSee('Enter as admin')->assertDontSee('Enter as user');

        $this->post('/demo/admin')->assertNotFound();
        $this->post('/demo/user')->assertNotFound();
        expect(User::count())->toBe(0);
    });

    it('tells the app that there is nothing to offer', function () {
        $this->getJson('/api/v1/demo')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.personas', []);
        $this->postJson('/api/v1/demo/admin')->assertNotFound();
        expect(User::count())->toBe(0);
    });
});

describe('with demo sign-in on', function () {
    beforeEach(fn () => config(['qistas.demo_login.enabled' => true]));

    it('offers an admin and a user on the sign-in page, saying what each one is', function () {
        $this->get('/login')->assertOk()
            ->assertSee('Enter as admin')->assertSee('Every feature, on the Pro plan')
            ->assertSee('Enter as user')->assertSee('The Free plan, with its limits')
            ->assertSee(route('demo.start', 'admin'), false)
            ->assertSee(route('demo.start', 'user'), false);
    });

    it('signs the visitor in as an admin with every feature and sample data', function () {
        $this->post('/demo/admin')->assertRedirect(route('app.dashboard'));

        $user = demoUsers()->sole();
        $tenant = $user->tenants()->sole();

        $this->assertAuthenticatedAs($user);
        expect($tenant->is_demo)->toBeTrue()
            ->and($tenant->currentPlan()->key)->toBe('pro')
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and(demoCustomerCount($tenant))->toBe(4)
            ->and($user->email)->toEndWith('@demo.qistas.test')
            ->and($user->demo_expires_at->isBetween(now()->addHours(11), now()->addHours(13)))->toBeTrue();
    });

    it('signs the visitor in as a user on the Free plan, with its limits', function () {
        $this->post('/demo/user')->assertRedirect(route('app.dashboard'));

        $tenant = demoUsers()->sole()->tenants()->sole();

        expect($tenant->currentPlan()->key)->toBe('free');
        $this->get('/app')->assertOk()->assertSee('4 of 20');
    });

    it('never makes platform staff, so the admin area stays closed', function () {
        $this->post('/demo/admin');

        $user = demoUsers()->sole();
        expect($user->platform_role)->toBeNull()->and($user->isPlatformAdmin())->toBeFalse();
        $this->get('/admin')->assertNotFound();
        $this->post('/admin/test/open')->assertNotFound();
    });

    it('gives every press its own account, workspace and data', function () {
        $this->post('/demo/admin');
        $first = demoUsers()->sole();
        auth()->logout();

        $this->post('/demo/user');

        expect(demoUsers())->toHaveCount(2)
            ->and(Tenant::where('is_demo', true)->count())->toBe(2)
            ->and(User::query()->pluck('email')->unique())->toHaveCount(2);

        // The second visitor cannot see the first one's customers.
        $this->get('/app/customers')->assertOk();
        expect(app(CurrentTenant::class)->get()?->id)->not->toBe($first->tenants()->sole()->id);
    });

    it('does not let a signed-in person start another demo on top of their session', function () {
        [$owner] = owner();

        $this->actingAs($owner)->post('/demo/admin')->assertRedirect();

        expect(demoUsers())->toHaveCount(0);
    });

    it('ignores personas that do not exist', function (string $persona) {
        // No such route: not found, or method not allowed because the catch-all page only answers GET.
        expect($this->post("/demo/{$persona}")->status())->toBeIn([404, 405]);
        expect(User::count())->toBe(0);
    })->with(['superadmin', 'owner', 'root', '..%2fadmin']);

    it('records that a demo began', function () {
        $this->post('/demo/user');

        expect(AuditLog::where('action', 'demo.started')->exists())->toBeTrue();
    });

    it('gives a demo visitor a way to a real account, without a banner explaining the demo', function () {
        $this->post('/demo/admin');

        $this->get('/app')->assertOk()
            ->assertSee(route('demo.leave'), false)
            ->assertSee('Create my free account')
            ->assertDontSee('Demo workspace')
            ->assertDontSee('Sample data, cleared a few hours after you started');
    });

    it('does not offer a customer’s own workspace the demo’s way out', function () {
        [$owner] = owner();

        $this->actingAs($owner)->get('/app')->assertOk()
            ->assertDontSee(route('demo.leave'), false)
            ->assertDontSee('Create my free account');
    });

    it('lets a demo visitor leave for the sign-up page, deleting what they made', function () {
        $this->post('/demo/admin');
        $user = demoUsers()->sole();
        $tenantId = $user->tenants()->sole()->id;

        $this->post('/demo-leave')->assertRedirect(route('register'));

        $this->assertGuest();
        expect(User::find($user->id))->toBeNull()->and(Tenant::find($tenantId))->toBeNull();
    });

    it('refuses the leave button to everyone who is not a demo visitor', function () {
        [$owner, $tenant] = owner();

        $this->actingAs($owner)->post('/demo-leave')->assertNotFound();

        expect(User::find($owner->id))->not->toBeNull()->and(Tenant::find($tenant->id))->not->toBeNull();
    });
});

describe('keeping the demo from being abused', function () {
    beforeEach(fn () => config(['qistas.demo_login.enabled' => true]));

    it('allows only a few demos an hour from one address', function () {
        config(['qistas.demo_login.per_hour' => 2]);

        $this->post('/demo/user')->assertRedirect();
        auth()->logout();
        $this->post('/demo/user')->assertRedirect();
        auth()->logout();
        $this->post('/demo/user')->assertStatus(429);

        expect(demoUsers())->toHaveCount(2);
    });

    it('stops making accounts when there are too many, and says so', function () {
        config(['qistas.demo_login.max_accounts' => 1]);
        $this->post('/demo/user');
        auth()->logout();

        $this->post('/demo/admin')->assertRedirect(route('login'))->assertSessionHas('warning');

        expect(demoUsers())->toHaveCount(1);
    });

    it('clears expired demos, and with them room for new ones', function () {
        config(['qistas.demo_login.max_accounts' => 1]);
        $this->post('/demo/user');
        $old = demoUsers()->sole();
        $old->forceFill(['demo_expires_at' => now()->subMinute()])->save();
        auth()->logout();

        $this->post('/demo/admin')->assertRedirect(route('app.dashboard'));

        expect(User::find($old->id))->toBeNull()->and(demoUsers())->toHaveCount(1);
    });

    it('deletes an expired demo completely: workspace, data, sessions and tokens', function () {
        $demo = app(DemoAccess::class);
        config(['qistas.demo_login.enabled' => true]);
        $user = $demo->start('admin');
        $tenantId = $user->tenants()->sole()->id;
        $user->createToken('phone', ['app']);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $user->forceFill(['demo_expires_at' => now()->subHour()])->save();

        expect($demo->prune())->toBe(1);

        expect(User::find($user->id))->toBeNull()
            ->and(Tenant::find($tenantId))->toBeNull()
            ->and(DB::table('customers')->where('tenant_id', $tenantId)->count())->toBe(0)
            ->and(DB::table('contracts')->where('tenant_id', $tenantId)->count())->toBe(0)
            ->and(DB::table('transactions')->where('tenant_id', $tenantId)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
    });

    it('never touches a real account, however old', function () {
        [$owner, $tenant] = owner();
        $owner->forceFill(['created_at' => now()->subYears(2)])->save();
        config(['qistas.demo_login.enabled' => true]);

        $this->post('/demo/user');
        demoUsers()->each->forceFill(['demo_expires_at' => now()->subHour()])->each->save();

        expect(app(DemoAccess::class)->prune())->toBe(1)
            ->and(User::find($owner->id))->not->toBeNull()
            ->and(Tenant::find($tenant->id))->not->toBeNull();
    });

    it('can be run from the command line', function () {
        config(['qistas.demo_login.enabled' => true]);
        $user = app(DemoAccess::class)->start('user');
        $user->forceFill(['demo_expires_at' => now()->subHour()])->save();

        $this->artisan('qistas:prune-demo')->expectsOutputToContain('1 expired demo')->assertSuccessful();

        expect(demoUsers())->toHaveCount(0);
    });
});

describe('in the app', function () {
    beforeEach(fn () => config(['qistas.demo_login.enabled' => true]));

    it('lists what is on offer, in the app’s language', function () {
        $this->getJson('/api/v1/demo', ['Accept-Language' => 'fr'])->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.personas.0.key', 'admin')
            ->assertJsonPath('data.personas.0.plan', 'pro')
            ->assertJsonPath('data.personas.1.key', 'user')
            ->assertJsonPath('data.personas.1.plan', 'free')
            ->assertJsonPath('data.hours', 12);
    });

    it('signs in as an admin with every feature, exactly like a normal sign-in', function () {
        $response = $this->postJson('/api/v1/demo/admin', ['device_name' => 'Pixel'])->assertCreated()
            ->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.tenant.is_demo', true)
            ->assertJsonPath('data.tenant.is_test', false)
            ->assertJsonPath('data.tenant.role', 'owner')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');
        expect($token)->toMatch('/^\d+\|/');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.is_demo', true);
        $this->withToken($token)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(4, 'data');
    });

    it('signs in as a user on the Free plan', function () {
        $this->postJson('/api/v1/demo/user')->assertCreated()
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.entitlements.customers.limit', 20);
    });

    it('lets the token live only as long as the demo', function () {
        $response = $this->postJson('/api/v1/demo/user')->assertCreated();

        $expires = Carbon\Carbon::parse($response->json('data.expires_at'));
        expect($expires->isBetween(now()->addHours(11), now()->addHours(13)))->toBeTrue();
    });

    it('is not platform staff and cannot open the admin area', function () {
        $token = $this->postJson('/api/v1/demo/admin')->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        expect(demoUsers()->sole()->isPlatformAdmin())->toBeFalse();
    });

    it('is limited per address and says so in the API’s own shape', function () {
        config(['qistas.demo_login.per_hour' => 1]);

        $this->postJson('/api/v1/demo/user')->assertCreated();
        $this->postJson('/api/v1/demo/user')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    });

    it('answers 503 when the demo is full', function () {
        config(['qistas.demo_login.max_accounts' => 1]);
        $this->postJson('/api/v1/demo/user')->assertCreated();

        $this->postJson('/api/v1/demo/admin')->assertStatus(503)->assertJsonPath('error.code', 'demo_busy');
        expect(demoUsers())->toHaveCount(1);
    });
});

describe('when the whole site is a demo', function () {
    it('offers the same buttons without a separate switch', function () {
        config(['qistas.demo' => true, 'qistas.demo_login.enabled' => false]);

        $this->get('/login')->assertSee('Enter as admin')->assertSee('Enter as user');
    });
});
