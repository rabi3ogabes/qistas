<?php

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Sandbox\TestWorkspace;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;

/** A platform administrator who has finished the second-factor set-up the console requires. */
function platformAdmin(string $role = 'super_admin'): User
{
    return User::factory()->create([
        'platform_role' => $role,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
}

/** @return array{customers: int, contracts: int, payments: int} what is stored in a workspace */
function inside(Tenant $tenant): array
{
    return app(CurrentTenant::class)->use($tenant, fn () => [
        'customers' => Customer::count(),
        'contracts' => Contract::count(),
        'payments' => Transaction::count(),
    ]);
}

describe('who may use the test tools', function () {
    it('keeps the admin area out of sight for everyone who is not platform staff', function () {
        [$owner] = owner();

        $this->actingAs($owner)->get('/admin')->assertNotFound();
        $this->actingAs($owner)->post('/admin/test/open')->assertNotFound();
    });

    it('asks a visitor who is not signed in to sign in', function () {
        $this->get('/admin')->assertRedirect('/login');
        $this->post('/admin/test/open')->assertRedirect('/login');

        expect(Tenant::where('is_test', true)->count())->toBe(0);
    });

    it('sends an admin without a second factor to set it up first', function () {
        $admin = User::factory()->create(['platform_role' => 'admin']);

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('security'));
        $this->actingAs($admin)->post('/admin/test/open')->assertRedirect(route('security'));

        expect(Tenant::where('is_test', true)->count())->toBe(0);
    });

    it('refuses the service itself to anyone who is not an admin', function () {
        [$owner] = owner();

        expect(fn () => app(TestWorkspace::class)->open($owner))->toThrow(AuthorizationException::class);
        expect(Tenant::where('is_test', true)->count())->toBe(0);
    });
});

describe('the admin home', function () {
    it('offers one-click tests of the dashboard and of the app', function () {
        $this->actingAs(platformAdmin())->get('/admin')
            ->assertOk()
            ->assertSee('Test the dashboard')
            ->assertSee('Test the app')
            ->assertSee(route('admin.test.open'), false);
    });

    it('says whether test mode is on', function () {
        $admin = platformAdmin();

        $this->actingAs($admin)->get('/admin')->assertSee('Test mode is off');

        $this->actingAs($admin)->post('/admin/test/open');

        $this->actingAs($admin->fresh())->get('/admin')->assertSee('Test mode is on');
    });

    it('is where an admin who has no workspace of their own lands instead of an error', function () {
        $this->actingAs(platformAdmin())->get('/app')->assertRedirect(route('admin.home'));
    });
});

describe('opening the test workspace', function () {
    it('creates a sandbox with sample data and takes the admin into it', function () {
        $admin = platformAdmin();

        $this->actingAs($admin)->post('/admin/test/open')->assertRedirect(route('app.dashboard'));

        $test = Tenant::where('is_test', true)->sole();
        expect($test->users()->pluck('users.id')->all())->toBe([$admin->id])
            ->and($test->currentPlan()->isFree())->toBeTrue()
            ->and($test->owner_user_id)->toBe($admin->id)
            ->and($admin->fresh()->current_tenant_id)->toBe($test->id)
            ->and(inside($test))->toBe(['customers' => 4, 'contracts' => 3, 'payments' => 7]);
    });

    it('shows the dashboard of that workspace, marked as a test', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        $this->actingAs($admin->fresh())->get('/app')
            ->assertOk()
            ->assertSee('Test workspace')
            ->assertSee(route('admin.test.reset'), false);
        $this->actingAs($admin->fresh())->get('/app/customers')->assertOk()->assertSee('Omar Khalil');
    });

    it('reuses the same sandbox the next time instead of piling up copies', function () {
        $admin = platformAdmin();

        $this->actingAs($admin)->post('/admin/test/open');
        $first = Tenant::where('is_test', true)->sole();

        $this->actingAs($admin->fresh())->post('/admin/test/leave');
        $this->actingAs($admin->fresh())->post('/admin/test/open');

        $again = Tenant::where('is_test', true)->sole();
        expect($again->id)->toBe($first->id)->and(inside($again)['customers'])->toBe(4);
    });

    it('never touches or shows a customer’s real workspace', function () {
        [$owner, $real] = owner();
        $admin = platformAdmin();
        $before = inside($real);

        $this->actingAs($admin)->post('/admin/test/open');

        expect(inside($real))->toBe($before)
            ->and($real->fresh()->is_test)->toBeFalse()
            ->and($owner->fresh()->current_tenant_id)->toBeNull();
        // The sandbox belongs to the admin alone.
        $this->actingAs($owner)->get('/app/customers')->assertDontSee('Omar Khalil');
    });

    it('leaves a trail in the audit log', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        expect(AuditLog::where('action', 'admin.test_workspace.opened')->where('user_id', $admin->id)->exists())->toBeTrue();
    });
});

describe('trying the plans', function () {
    it('starts on Free, with its limits', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        $this->actingAs($admin->fresh())->get('/app')->assertSee('Free');
    });

    it('switches to Pro and back, so limits and upgrade prompts can both be tried', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');
        $test = Tenant::where('is_test', true)->sole();

        $this->actingAs($admin->fresh())->post('/admin/test/plan', ['plan' => 'pro'])->assertRedirect();
        expect($test->fresh()->currentPlan()->key)->toBe('pro');

        $this->actingAs($admin->fresh())->post('/admin/test/plan', ['plan' => 'free'])->assertRedirect();
        expect($test->fresh()->currentPlan()->key)->toBe('free');
    });

    it('accepts only plans that exist', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        $this->actingAs($admin->fresh())->post('/admin/test/plan', ['plan' => 'platinum'])->assertSessionHasErrors('plan');
    });

    it('does nothing when test mode was never opened', function () {
        $this->actingAs(platformAdmin())->post('/admin/test/plan', ['plan' => 'pro'])->assertRedirect(route('admin.home'));

        expect(Tenant::where('is_test', true)->count())->toBe(0);
    });
});

describe('starting again', function () {
    it('wipes the sandbox and rebuilds the sample data', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');
        $old = Tenant::where('is_test', true)->sole();

        // The admin makes a mess.
        app(CurrentTenant::class)->use($old, fn () => Customer::first()->delete());
        expect(inside($old)['customers'])->toBe(3);

        $this->actingAs($admin->fresh())->post('/admin/test/reset')->assertRedirect(route('app.dashboard'));

        $new = Tenant::where('is_test', true)->sole();
        expect($new->id)->not->toBe($old->id)
            ->and(Tenant::find($old->id))->toBeNull()
            ->and(inside($new))->toBe(['customers' => 4, 'contracts' => 3, 'payments' => 7])
            ->and($admin->fresh()->current_tenant_id)->toBe($new->id);
    });

    it('keeps the plan the admin was trying', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');
        $this->actingAs($admin->fresh())->post('/admin/test/plan', ['plan' => 'pro']);

        $this->actingAs($admin->fresh())->post('/admin/test/reset');

        expect(Tenant::where('is_test', true)->sole()->currentPlan()->key)->toBe('pro');
    });
});

describe('leaving test mode', function () {
    it('returns an admin with no workspace of their own to the admin home', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        $this->actingAs($admin->fresh())->post('/admin/test/leave')->assertRedirect(route('admin.home'));

        expect($admin->fresh()->current_tenant_id)->toBeNull();
        $this->actingAs($admin->fresh())->get('/app')->assertRedirect(route('admin.home'));
    });

    it('returns an admin who has a real workspace to that one', function () {
        [$admin, $real] = owner();
        $admin->forceFill([
            'platform_role' => 'admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)->post('/admin/test/open');
        $this->actingAs($admin->fresh())->get('/app/customers')->assertSee('Omar Khalil');

        $this->actingAs($admin->fresh())->post('/admin/test/leave');

        expect($admin->fresh()->primaryTenant()->id)->toBe($real->id);
        $this->actingAs($admin->fresh())->get('/app/customers')->assertDontSee('Omar Khalil');
    });

    it('does not keep an admin in a workspace they no longer belong to', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');
        $test = Tenant::where('is_test', true)->sole();

        $test->users()->detach($admin->id);

        expect($admin->fresh()->primaryTenant())->toBeNull();
    });
});

describe('the app', function () {
    it('opens the same test workspace for the admin’s sign-in, and says it is a test', function () {
        $admin = platformAdmin();
        $this->actingAs($admin)->post('/admin/test/open');

        Sanctum::actingAs($admin->fresh(), ['app']);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.tenant.is_test', true)
            ->assertJsonPath('data.plan.key', 'free');
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(4, 'data');
    });

    it('reports a customer’s own workspace as not a test', function () {
        [$owner] = apiOwner();

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.is_test', false);
    });
});
