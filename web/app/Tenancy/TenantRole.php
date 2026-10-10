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

    /** Sees who funds the business and their money (Win Plan PP3): everyone but a collector. */
    public function seesInvestors(): bool
    {
        return $this !== self::Collector;
    }

    /** Adds investors and records their deposits and withdrawals. */
    public function managesInvestors(): bool
    {
        return $this->seesInvestors() && $this->canWrite();
    }

    /** May take the whole of the books out (Win Plan PP10): the people who run the business and its accountant. */
    public function canExport(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Accountant], true);
    }

    /** May delete records. */
    public function canDelete(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
