<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureStatus;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use App\Tenancy\TenantRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| A page asks what the plan allows many times (the menu, each button, each check). The answer is read from the database
| once per request and remembered, and forgotten the moment anything it depends on changes (a plan, a switch, an
| override, a role) or the clock moves, so it is never out of date.
*/

/** How many times $table was read while $run ran. */
function readsOf(string $table, Closure $run): int
{
    $reads = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$reads, $table): void {
        if (preg_match('/^\s*select\b/i', $query->sql) && preg_match('/\bfrom\s+"?'.$table.'"?/i', $query->sql)) {
            $reads++;
        }
    });
    $run();

    return $reads;
}

it('reads the plan and the switches once for a whole page', function () {
    [$owner] = owner();

    expect(readsOf('subscriptions', fn () => $this->actingAs($owner)->get('/app/customers')->assertOk()))->toBe(1)
        ->and(readsOf('platform_features', fn () => $this->actingAs($owner)->get('/app/customers')->assertOk()))->toBe(1);
});

it('sees a new plan at once, in the same request', function () {
    [, $tenant] = owner();
    expect(Entitlements::for($tenant)->check(Feature::AdvancedReports)->enabled())->toBeFalse();

    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    expect(Entitlements::for($tenant)->check(Feature::AdvancedReports)->enabled())->toBeTrue();
});

it('sees a switch turned off at once', function () {
    [, $tenant] = owner();
    app(FeatureControl::class)->setState(Feature::CustomerTags, PlatformState::On, null, null);
    expect(Entitlements::for($tenant)->check(Feature::CustomerTags)->status())->not->toBe(FeatureStatus::PlatformOff);

    app(FeatureControl::class)->setState(Feature::CustomerTags, PlatformState::Off, null, null);

    expect(Entitlements::for($tenant)->check(Feature::CustomerTags)->status())->toBe(FeatureStatus::PlatformOff);
});

it('sees a plan run out when the clock moves past its end', function () {
    $this->travelTo('2026-10-16 09:00:00');
    [, $tenant] = owner();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addDay());
    expect(Entitlements::for($tenant)->check(Feature::AdvancedReports)->enabled())->toBeTrue();

    $this->travelTo(now()->addDays(2 + (int) config('qistas.billing.grace_days')));

    expect(Entitlements::for($tenant)->check(Feature::AdvancedReports)->enabled())->toBeFalse();
});

it('sees a role changed at once', function () {
    [, $tenant] = owner();
    $member = memberAs('viewer', $tenant);
    expect($member->roleIn($tenant->id))->toBe(TenantRole::Viewer);

    DB::table('tenant_users')->where(['user_id' => $member->id, 'tenant_id' => $tenant->id])->update(['role' => 'manager']);

    expect($member->roleIn($tenant->id))->toBe(TenantRole::Manager);
});

it('reads a person’s role once for a whole page', function () {
    [$owner] = owner();

    expect(readsOf('tenant_users', fn () => $this->actingAs($owner)->get('/app/customers')->assertOk()))->toBeLessThanOrEqual(3);
});
