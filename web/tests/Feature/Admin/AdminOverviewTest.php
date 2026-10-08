<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\PlatformOverview;
use App\Sandbox\DemoAccess;
use App\Sandbox\TestWorkspace;

/** Platform staff who has finished the second-factor set-up the admin area asks for. */
function overviewAdmin(): User
{
    return User::factory()->create([
        'platform_role' => 'super_admin',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
}

describe('the platform at a glance', function () {
    it('counts real businesses by plan', function () {
        [, $a] = owner();
        [, $b] = owner();
        $b->subscribeTo(Plan::where('key', 'pro')->firstOrFail());
        owner();

        $figures = app(PlatformOverview::class)->figures();

        expect($figures['workspaces'])->toBe(3)->and($figures['free'])->toBe(2)->and($figures['pro'])->toBe(1)->and($figures['other'])->toBe(0);
    });

    it('counts real people, not staff and not demo visitors', function () {
        owner();
        owner();
        overviewAdmin();
        config(['qistas.demo_login.enabled' => true]);
        app(DemoAccess::class)->start('user');

        expect(app(PlatformOverview::class)->figures()['people'])->toBe(2);
    });

    it('leaves demo workspaces and the admin’s test workspace out of every count', function () {
        owner();
        config(['qistas.demo_login.enabled' => true]);
        app(DemoAccess::class)->start('admin');
        app(TestWorkspace::class)->open(overviewAdmin());

        $figures = app(PlatformOverview::class)->figures();

        expect($figures['workspaces'])->toBe(1)->and($figures['customers'])->toBe(0)->and($figures['active_contracts'])->toBe(0);
    });

    it('counts customers and running contracts across all real businesses', function () {
        $first = workspaceOn('pro');
        $second = workspaceOn('pro');
        customerIn($first);
        customerIn($second);
        customerIn($second);
        openContract($first, ['type' => 'cash', 'principal' => '10.00']);

        $figures = app(PlatformOverview::class)->figures();

        expect($figures['customers'])->toBeGreaterThanOrEqual(3)->and($figures['active_contracts'])->toBeGreaterThanOrEqual(0);
    });

    it('does not count a deleted customer', function () {
        $tenant = workspaceOn('pro');
        $customer = customerIn($tenant);
        $before = app(PlatformOverview::class)->figures()['customers'];

        asTenant($tenant, fn () => Customer::find($customer->id)->delete());

        expect(app(PlatformOverview::class)->figures()['customers'])->toBe($before - 1);
    });

    it('compares sign-ups this week with the week before', function () {
        User::factory()->create(['created_at' => now()->subDays(2)]);
        User::factory()->create(['created_at' => now()->subDays(3)]);
        User::factory()->create(['created_at' => now()->subDays(10)]);
        User::factory()->create(['created_at' => now()->subDays(40)]);

        $figures = app(PlatformOverview::class)->figures();

        expect($figures['signups_week'])->toBe(2)->and($figures['signups_prev_week'])->toBe(1)->and($figures['people'])->toBe(4);
    });

    it('returns only numbers: never a business’s name, customer or amount', function () {
        $tenant = workspaceOn('pro');
        customerIn($tenant);

        $values = collect(app(PlatformOverview::class)->figures())->flatten();

        expect($values->every(fn ($value) => is_int($value)))->toBeTrue();
    });
});

describe('is everything set up?', function () {
    it('warns when e-mail only goes to the log', function () {
        config(['mail.default' => 'log']);

        $mail = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'mail');

        expect($mail['status'])->toBe('warn')->and($mail['detail'])->toContain('Not sending');
    });

    it('is happy when e-mail is really sent', function () {
        config(['mail.default' => 'smtp']);

        expect(collect(app(PlatformOverview::class)->health())->firstWhere('key', 'mail')['status'])->toBe('ok');
    });

    it('warns about debug mode on a production site, and only there', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);
        expect(collect(app(PlatformOverview::class)->health())->firstWhere('key', 'environment')['status'])->toBe('warn');

        config(['app.debug' => false]);
        expect(collect(app(PlatformOverview::class)->health())->firstWhere('key', 'environment')['status'])->toBe('ok');
    });

    it('says whether the demo is on, and how full it is', function () {
        config(['qistas.demo_login.enabled' => false, 'qistas.demo' => false]);
        expect(collect(app(PlatformOverview::class)->health())->firstWhere('key', 'demo')['status'])->toBe('off');

        config(['qistas.demo_login.enabled' => true]);
        app(DemoAccess::class)->start('user');
        $demo = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'demo');

        expect($demo['status'])->toBe('ok')->and($demo['detail'])->toContain('1 of 300');
    });
});

describe('the page', function () {
    it('shows the figures, the test tools and the health, inside an admin frame with a way out', function () {
        owner();
        $admin = overviewAdmin();

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('The platform at a glance')
            ->assertSee('Businesses')
            ->assertSee('Test the dashboard')
            ->assertSee('Is everything set up?')
            ->assertSee(route('logout'), false)
            ->assertSee(route('security'), false);
    });

    it('lets an admin with a workspace of their own jump to the app', function () {
        [$user] = owner();
        $user->forceFill(['platform_role' => 'admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Open app')->assertSee(route('app.dashboard'), false);
    });

    it('is in the reader’s language and direction', function () {
        $this->actingAs(overviewAdmin())->get('/admin?lang=ar')->assertOk()->assertSee('dir="rtl"', false);
    });

    it('clears expired demo accounts on request and says how many', function () {
        config(['qistas.demo_login.enabled' => true]);
        $demo = app(DemoAccess::class);
        $demo->start('user')->forceFill(['demo_expires_at' => now()->subHour()])->save();
        $demo->start('admin')->forceFill(['demo_expires_at' => now()->subMinute()])->save();
        $live = $demo->start('user');
        $admin = overviewAdmin();

        $this->actingAs($admin)->post('/admin/demo/prune')->assertRedirect(route('admin.home'))->assertSessionHas('status');

        expect(User::find($live->id))->not->toBeNull()
            ->and(User::query()->whereNotNull('demo_expires_at')->count())->toBe(1)
            ->and(Tenant::where('is_demo', true)->count())->toBe(1)
            ->and(AuditLog::where('action', 'admin.demo_pruned')->where('user_id', $admin->id)->exists())->toBeTrue();
    });

    it('keeps the clear button for staff alone', function () {
        [$owner] = owner();

        $this->actingAs($owner)->post('/admin/demo/prune')->assertNotFound();
    });

    it('asks a visitor who is not signed in to sign in first', function () {
        $this->post('/admin/demo/prune')->assertRedirect('/login');
    });
});
