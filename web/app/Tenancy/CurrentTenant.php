<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Holds the workspace the current request, job or command acts for.
 * Registered as a scoped binding so state never leaks between requests (Octane) or queued jobs.
 */
final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    /** Run $callback as $tenant, then put the previous context back, even if $callback throws. */
    public function use(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
