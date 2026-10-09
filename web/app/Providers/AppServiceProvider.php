<?php

namespace App\Providers;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureCatalogue;
use App\Entitlements\UsageMeters;
use App\Listeners\AuditAuthEvents;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Support\AppFirstTranslationLoader;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use App\Theme\Appearance;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('translation.loader', fn ($loader, $app) => AppFirstTranslationLoader::from($loader, lang_path('app')));

        // Scoped: a fresh, empty tenant context for every request and every queued job.
        $this->app->scoped(CurrentTenant::class);

        // A registry of closures set up once at boot (each module registers its own meter); safe to share.
        $this->app->singleton(UsageMeters::class);

        // The settings features declare for the workspace owner (see App\Settings). One per application instance.
        $this->app->singleton(SettingsRegistry::class);

        // One per request: the live look is read once however many parts of a page ask for it.
        $this->app->scoped(Appearance::class);

        // What the admin's tools know about each feature. The enum answers; in the local environment a developer may
        // unlock some core switches to try the cockpit (QISTAS_PREVIEW_UNLOCK), and nowhere else.
        $this->app->singleton(FeatureCatalogue::class, function ($app): FeatureCatalogue {
            $unlocked = $app->environment('local')
                ? array_filter(array_map('trim', explode(',', (string) config('qistas.preview_unlock'))))
                : [];

            return $unlocked === []
                ? new FeatureCatalogue
                : new FeatureCatalogue(locked: fn (Feature $feature): bool => $feature->isCore() && ! in_array($feature->value, $unlocked, true));
        });

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
        // Our own sentences live in lang/app/*.json, apart from the package-managed framework translations, and
        // win where both have one (see AppFirstTranslationLoader).

        Paginator::defaultView('pagination.qistas');
        Paginator::defaultSimpleView('pagination.qistas');

        // A mistyped or malicious attribute fails loudly in development and tests instead of vanishing.
        // In production unfillable attributes are still never written; they are just not reported as errors.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Behind Vercel or a load balancer the request arrives over plain HTTP from the proxy; trusting its
        // X-Forwarded-* headers is what lets the app know the visitor used https and what their address is.
        if (($proxies = config('qistas.trusted_proxies')) !== null) {
            TrustProxies::at($proxies);
        }

        // The single password policy for sign-up, reset and change. Breach lookups use k-anonymity (only the
        // first 5 characters of a SHA-1 hash leave the server) and are skipped in the test suite.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers()->symbols()
            ->when(! $this->app->environment('testing'), fn (Password $rule) => $rule->uncompromised()));

        Event::subscribe(AuditAuthEvents::class);

        // Only a super admin changes what the platform has on, off or in beta; an admin may look.
        Gate::define('manage-platform-features', fn (User $user): bool => $user->platform_role === 'super_admin');

        // The same for the look of the product (colours, pictures, welcome banners).
        Gate::define('manage-appearance', fn (User $user): bool => $user->platform_role === 'super_admin');

        // @feature('key') ... @endfeature: shown only when the feature is on for the current workspace (not plan-locked,
        // not switched off by the platform). Nothing is shown outside a workspace.
        Blade::if('feature', function (string $key): bool {
            $tenant = app(CurrentTenant::class)->get();
            $feature = Feature::tryFrom($key);

            return $tenant !== null && $feature !== null && Entitlements::for($tenant)->check($feature)->enabled();
        });

        // How each counted feature is measured. Every module that has a limit registers its meter here.
        $meters = $this->app->make(UsageMeters::class);
        $meters->register(Feature::Customers, fn (Tenant $tenant): int => Customer::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->count());
        // API access tokens made on purpose (see TokenController); a person's own phone sign-ins are not counted.
        $meters->register(Feature::ApiTokens, fn (Tenant $tenant): int => DB::table('personal_access_tokens as tokens')
            ->join('tenant_users as members', 'members.user_id', '=', 'tokens.tokenable_id')
            ->where('members.tenant_id', $tenant->id)
            ->where('tokens.tokenable_type', (new User)->getMorphClass())
            ->where('tokens.abilities', 'like', '%"integration"%')
            ->count());
        // Only contracts still running count: settled and cancelled ones free their place.
        $meters->register(Feature::ActiveContracts, fn (Tenant $tenant): int => Contract::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }
}
