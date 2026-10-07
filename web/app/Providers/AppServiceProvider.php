<?php

namespace App\Providers;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty tenant context for every request and every queued job.
        $this->app->scoped(CurrentTenant::class);
    }

    public function boot(): void
    {
        // A mistyped or malicious attribute fails loudly in development and tests instead of vanishing.
        // In production unfillable attributes are still never written; they are just not reported as errors.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
