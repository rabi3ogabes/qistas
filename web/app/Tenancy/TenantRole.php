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

    /** The role's name as people read it. */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::Accountant => __('Accountant'),
            self::Collector => __('Collector'),
            self::Viewer => __('Viewer'),
        };
    }

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

    /** Sees ID numbers and ID photos (Win Plan K5): the people who run the business and its accountant. */
    public function seesIdentities(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Accountant], true);
    }

    /** May bring a whole file of customers in (Win Plan K9): it adds many records at once, so the owner's or a manager's. */
    public function canImport(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    /** May take the whole of the books out (Win Plan PP10): the people who run the business and its accountant. */
    public function canExport(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Accountant], true);
    }

    /** Buys and pays for the workspace's plan (Win Plan PP13): the owner alone. */
    public function managesBilling(): bool
    {
        return $this === self::Owner;
    }

    /** May delete records. */
    public function canDelete(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
