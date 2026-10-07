<?php

namespace App\Tenancy;

use RuntimeException;

/** Thrown when tenant-owned data is written while no workspace is active. Always a programming error. */
final class NoTenantContext extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct("Refusing to write {$model} without an active tenant context.");
    }
}
