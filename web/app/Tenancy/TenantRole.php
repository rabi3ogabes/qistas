<?php

namespace App\Tenancy;

/** A person's role inside one workspace (tenant_users.role). Platform staff roles live on the user instead. */
enum TenantRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Collector = 'collector';
    case Viewer = 'viewer';

    /** May add and change records. */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    /** May change the workspace's own choices (its instalment tools). */
    public function canManageSettings(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    /** May delete records. */
    public function canDelete(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
