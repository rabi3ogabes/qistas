<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the money ledger. Immutable: once written it is never edited or deleted. A mistake is corrected
 * by a reversal (the same amount, negated, pointing back at the original). Written only by RecordPayment,
 * VoidTransaction and CreateContract, never from request data.
 *
 * @property string $type payment | down_payment | reversal (money in); charge | charge_reversal (an open contract's "they took")
 * @property string $method
 * @property string $amount signed: payments are positive, reversals negative
 * @property Carbon $paid_at
 * @property string|null $note
 * @property string|null $idempotency_key
 * @property string|null $reverses_transaction_id
 * @property string|null $tag advance | refund | early_discount | unpaid, or imported (written by an import only)
 */
class Transaction extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** Money that came in (or went back out with a reversal): what "collected" and the payments lists count. */
    public const MONEY_IN = ['payment', 'down_payment', 'reversal'];

    /** What an open contract's customer took, and the reversal of it: owed, never money in. */
    public const CHARGES = ['charge', 'charge_reversal'];

    /** The words a line may carry, as a person chooses them. */
    public const TAGS = ['advance', 'refund', 'early_discount', 'unpaid'];

    /**
     * The tag an import writes on what a customer had paid before the shop used Qistas ("Paid before Qistas"). It is
     * money taken in the past, never cash in any of the shop's days, so the end-of-day figures (DayTotals) and the payday
     * pattern leave it out; it is still a payment everywhere else (what the customer owes, the reports, the investors).
     * Not one of TAGS: no person can choose it, so no payment can be hidden from a day's close by tagging it.
     */
    public const IMPORTED = 'imported';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger transactions are immutable; record a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Ledger transactions cannot be deleted; record a reversal instead.'));
    }

    /**
     * Money that came in, never a charge.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeMoneyIn(Builder $query): void
    {
        $query->whereIn('type', self::MONEY_IN);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> who took the money; null for lines written by the system */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<TransactionAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'paid_at' => 'datetime'];
    }
}
