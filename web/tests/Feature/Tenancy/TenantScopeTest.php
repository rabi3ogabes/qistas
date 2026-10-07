<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\NoTenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Widget;

beforeEach(function () {
    Schema::create('widgets', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->index();
        $table->string('name');
        $table->timestamps();
    });
});

function tenantWithOwner(string $name): array
{
    $tenant = Tenant::factory()->create(['name' => $name]);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => 'owner']);

    return [$tenant, $user];
}

it('hides rows that belong to other tenants', function () {
    [$a] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    $context = app(CurrentTenant::class);

    $context->use($a, fn () => Widget::create(['name' => 'a1']));
    $context->use($b, fn () => Widget::create(['name' => 'b1']));

    $context->set($a);
    expect(Widget::orderBy('name')->pluck('name')->all())->toBe(['a1']);
    $context->set($b);
    expect(Widget::orderBy('name')->pluck('name')->all())->toBe(['b1']);
});

it('fails closed: with no tenant context a query returns nothing, not everything', function () {
    [$a] = tenantWithOwner('A');
    app(CurrentTenant::class)->use($a, fn () => Widget::create(['name' => 'a1']));
    app(CurrentTenant::class)->clear();

    expect(Widget::count())->toBe(0)
        ->and(Widget::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});

it('refuses to create a tenant-owned row without a tenant context', function () {
    app(CurrentTenant::class)->clear();

    expect(fn () => Widget::create(['name' => 'orphan']))->toThrow(NoTenantContext::class);
});

it('ignores a tenant_id supplied by the caller and uses the current tenant', function () {
    [$a] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');

    $widget = app(CurrentTenant::class)->use($a, fn () => Widget::create(['name' => 'sneaky', 'tenant_id' => $b->id]));

    expect($widget->tenant_id)->toBe($a->id);
});

it('answers 404, never 403 or 200, when a user asks for another tenants record by id', function () {
    [$a, $userA] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    $mine = app(CurrentTenant::class)->use($a, fn () => Widget::create(['name' => 'mine']));
    $theirs = app(CurrentTenant::class)->use($b, fn () => Widget::create(['name' => 'theirs']));
    app(CurrentTenant::class)->clear();

    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/widgets/{widget}', fn (Widget $widget) => $widget->name);

    $this->actingAs($userA)->get("/_test/widgets/{$mine->id}")->assertOk()->assertSee('mine');
    $this->actingAs($userA)->get("/_test/widgets/{$theirs->id}")->assertNotFound();
});

it('sets the current tenant from the signed-in user', function () {
    [$a, $user] = tenantWithOwner('A');
    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/whoami', fn (CurrentTenant $t) => $t->get()->name);

    $this->actingAs($user)->get('/_test/whoami')->assertOk()->assertSee('A');
});

it('answers 403 when a signed-in user belongs to no workspace', function () {
    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/whoami', fn (CurrentTenant $t) => $t->get()->name);

    $this->actingAs(User::factory()->create())->get('/_test/whoami')->assertForbidden();
});

it('refuses to move an existing row to another tenant', function () {
    [$a] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    $context = app(CurrentTenant::class);
    $widget = $context->use($a, fn () => Widget::create(['name' => 'w']));

    expect(fn () => $context->use($a, fn () => $widget->update(['tenant_id' => $b->id])))->toThrow(LogicException::class);
});

it('restores the previous tenant after a scoped block, even when the block throws', function () {
    [$a] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    $context = app(CurrentTenant::class);
    $context->set($a);

    expect(fn () => $context->use($b, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect($context->id())->toBe($a->id);
});
