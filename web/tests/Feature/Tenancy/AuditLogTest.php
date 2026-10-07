<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Route;

it('records the action and what changed', function () {
    $entry = Audit::record('plan.changed', changes: ['from' => 'free', 'to' => 'pro']);

    expect($entry->action)->toBe('plan.changed')
        ->and($entry->changes)->toBe(['from' => 'free', 'to' => 'pro']);
});

it('captures the request ip and user agent', function () {
    Route::get('/_test/audit', fn () => response()->json(Audit::record('login.succeeded')->only(['ip', 'user_agent'])));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'PestBrowser/1.0')
        ->get('/_test/audit')
        ->assertExactJson(['ip' => '203.0.113.9', 'user_agent' => 'PestBrowser/1.0']);
});

it('attributes the entry to the signed-in user and the current tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $entry = app(CurrentTenant::class)->use($tenant, fn () => Audit::record('customer.created'));

    expect($entry->user_id)->toBe($user->id)->and($entry->tenant_id)->toBe($tenant->id);
});

it('is append-only: entries can neither be edited nor deleted', function () {
    $entry = Audit::record('login.succeeded');

    expect(fn () => $entry->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::count())->toBe(1);
});
