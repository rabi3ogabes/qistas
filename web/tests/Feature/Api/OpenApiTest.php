<?php

use App\Entitlements\Feature;
use App\Models\CustomerPayPattern;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;

/**
 * Every endpoint the API serves is described in docs/api/openapi.yaml, and every described endpoint exists: the
 * document apps are built from cannot drift from the code. Only the path and method are compared here.
 *
 * @return array<string, true> "METHOD /path" with parameter names erased
 */
function documentedOperations(): array
{
    $operations = [];
    $path = null;

    foreach (file(base_path('../docs/api/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^components:/', $line)) {
            break;
        }
        if (preg_match('/^  (\/[^:]*):\s*$/', $line, $match)) {
            $path = preg_replace('/\{[^}]*\}/', '{}', $match[1]);
        } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $match)) {
            $operations[strtoupper($match[1])." {$path}"] = true;
        }
    }

    return $operations;
}

/** @return array<string, true> */
function servedOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = preg_replace('/\{[^}]*\}/', '{}', '/'.substr($route->uri(), strlen('api/v1/')));
        // HEAD answers wherever GET does, and PATCH is an alias of PUT: neither is documented separately.
        $methods = array_diff($route->methods(), ['HEAD', 'PATCH']);

        foreach ($methods as $method) {
            $operations["{$method} {$path}"] = true;
        }
    }

    return $operations;
}

/** @return list<string> the property names a schema under components lists, in order */
function documentedProperties(string $schema): array
{
    $properties = [];
    $inside = false;

    foreach (file(base_path('../docs/api/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === "    {$schema}:") {
            $inside = true;
        } elseif ($inside && preg_match('/^    \S/', $line)) {
            break;
        } elseif ($inside && preg_match('/^        ([a-z_]+):/', $line, $match)) {
            $properties[] = $match[1];
        }
    }

    return $properties;
}

it('describes every field of the billing state the apps read', function () {
    apiOwner();

    expect(documentedProperties('BillingState'))->toBe(array_keys($this->getJson('/api/v1/billing')->assertOk()->json('data')));
});

it('describes every field of the payday pattern a customer’s page carries', function () {
    switchOn(Feature::PaydayLearning);
    [, $tenant] = apiOwner();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
    $customer = customerIn($tenant);
    asTenant($tenant, fn () => CustomerPayPattern::query()->create([
        'customer_id' => $customer->id, 'day' => 27, 'spread' => 2, 'months' => 6, 'within' => 83, 'confidence' => 'high', 'computed_at' => now(),
    ]));

    $sent = $this->getJson("/api/v1/customers/{$customer->id}")->assertOk()->json('data');

    expect(documentedProperties('PayPattern'))->toBe(array_keys($sent['pay_pattern']))
        ->and(documentedProperties('Customer'))->toContain('pay_pattern');
});

it('documents every endpoint the API serves', function () {
    expect(array_keys(array_diff_key(servedOperations(), documentedOperations())))->toBe([]);
});

it('describes only endpoints that exist', function () {
    expect(array_keys(array_diff_key(documentedOperations(), servedOperations())))->toBe([]);
});

it('has a document that can be read', function () {
    expect(documentedOperations())->not->toBeEmpty()->and(file_get_contents(base_path('../docs/api/openapi.yaml')))->toStartWith('openapi: 3.1.0');
});
