<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone whose money funds the business's contracts (Win Plan PP3): the business's own capital (the main investor,
 * one per workspace, made by App\Domain\Investors\MainInvestor) or a partner. Their money is the sum of their entries.
 * Which one is main, the currency and archiving are set by trusted code, never filled from a request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $commercial_registration
 * @property string $currency
 * @property bool $is_main
 * @property string $commission_percent of this partner's profit that goes to the main investor
 * @property string|null $notes
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'commercial_registration', 'commission_percent', 'notes'])]
class Investor extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'commission_percent' => 'decimal:4', 'archived_at' => 'datetime'];
    }

    /** @return HasMany<InvestorEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(InvestorEntry::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * The ones that can still fund new contracts.
     *
     * @param  Builder<Investor>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
