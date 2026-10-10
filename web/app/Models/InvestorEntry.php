<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of an investor's money. Immutable like the money ledger: a mistake is corrected by a reversal (the same
 * type and amount, negated, pointing back at the original). Amounts are signed for the investor's wallet. Written only
 * by App\Domain\Investors\InvestorLedger and App\Actions\Investors\*, never from request data.
 *
 * @property string $id
 * @property string $investor_id
 * @property string $type deposit | withdrawal | funding_out | funding_back | principal_back | profit_share | commission
 * @property string $amount signed: money into the wallet is positive
 * @property string|null $contract_id
 * @property string|null $transaction_id
 * @property string|null $reverses_entry_id
 * @property string|null $note
 * @property Carbon $occurred_on
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 */
class InvestorEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** The entries a person writes by hand; everything else follows from contracts and payments. */
    public const MANUAL = ['deposit', 'withdrawal'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Investor entries are immutable; record a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Investor entries cannot be deleted; record a reversal instead.'));
    }

    /** @return BelongsTo<Investor, $this> */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<User, $this> who wrote it; null for lines that follow from a payment */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_on' => 'date'];
    }
}
