<?php

namespace App\Providers;

use App\Entitlements\Feature;
use App\Entitlements\UsageMeters;
use App\Listeners\AuditAuthEvents;
use App\Models\Customer;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty tenant context for every request and every queued job.
        $this->app->scoped(CurrentTenant::class);

        // A registry of closures set up once at boot (each module registers its own meter); safe to share.
        $this->app->singleton(UsageMeters::class);

        // The passkey package registers sign-in endpoints on its own. They stay off until their UI ships.
        Passkeys::ignoreRoutes();

        // The breached-password lookup fails open when the service is down, but by default it waits 30 s
        // for it. Three seconds keeps sign-up responsive.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn ($verifier, $app) => new NotPwnedVerifier($app->make(HttpFactory::class), 3),
        );
    }

    public function boot(): void
    {
        // A mistyped or malicious attribute fails loudly in development and tests instead of vanishing.
        // In production unfillable attributes are still never written; they are just not reported as errors.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // The single password policy for sign-up, reset and change. Breach lookups use k-anonymity (only the
        // first 5 characters of a SHA-1 hash leave the server) and are skipped in the test suite.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers()->symbols()
            ->when(! $this->app->environment('testing'), fn (Password $rule) => $rule->uncompromised()));

        Event::subscribe(AuditAuthEvents::class);

        // How each counted feature is measured. Every module that has a limit registers its meter here.
        $meters = $this->app->make(UsageMeters::class);
        $meters->register(Feature::Customers, fn (Tenant $tenant): int => Customer::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->count());
    }
}
