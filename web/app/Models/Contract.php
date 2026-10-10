<?php

namespace App\Models;

use App\Support\Digits;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An agreement to repay a purchase in instalments (or a cash sale, which is a single instalment).
 *
 * Nothing is mass-assignable: contracts are written only by App\Actions\CreateContract, and afterwards only
 * their status changes (settled by the ledger, cancelled by an authorised person). The schedule is fixed.
 *
 * @property int $number
 * @property string $type
 * @property string $status
 * @property string $principal
 * @property string $down_payment
 * @property string $financed
 * @property string $markup_amount
 * @property string $total
 * @property Carbon $start_date
 * @property Carbon $first_due_date
 * @property int $installment_count
 * @property string $frequency
 * @property int $grace_days days after a due date before the instalment counts as late
 * @property string $markup_type
 * @property string $markup_value
 * @property string|null $notes
 * @property string|null $investor_id who funded it; null only for contracts made before investors, until the main investor takes them on
 * @property Carbon|null $settled_at
 * @property Carbon|null $cancelled_at
 */
class Contract extends Model
{
    use BelongsToTenant, HasUuids;

    /** The lists a person can choose between. "late" is a running contract with an instalment past its date. */
    public const VIEWS = ['active', 'late', 'settled', 'cancelled', 'all'];

    /** The human-readable reference shown to people, e.g. C-0042. */
    public function reference(): string
    {
        return 'C-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * One of VIEWS; anything else means the default, running contracts.
     *
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeInView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'all' => $query,
            'late' => $query->where('status', 'active')->whereExists(
                fn ($overdue) => $overdue->select(DB::raw(1))->from('installments')
                    ->whereColumn('installments.contract_id', 'contracts.id')
                    ->where('installments.status', '!=', 'paid')
                    ->whereDate('installments.grace_until', '<', today()),
            ),
            'settled', 'cancelled' => $query->where('status', $view),
            default => $query->where('status', 'active'),
        };
    }

    /**
     * By reference ("C-0042", "c0042" or a short number such as "42") or by anything that finds the customer.
     * Up to five digits are a contract number; a longer run of digits is a phone number.
     *
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim(Digits::toAscii((string) $term));

        if ($term === '') {
            return $query;
        }

        if (preg_match('/^(?:c-?\s*)?0*(\d{1,6})$/i', $term, $match) && (preg_match('/^c/i', $term) || strlen($term) <= 5)) {
            return $query->where('number', (int) $match[1]);
        }

        return $query->whereIn('customer_id', Customer::query()->withTrashed()->search($term)->select('id'));
    }

    /** @return BelongsTo<Investor, $this> */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        // A deleted customer's contracts stay readable.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'installment_count' => 'integer',
            'grace_days' => 'integer',
            'principal' => 'decimal:4',
            'down_payment' => 'decimal:4',
            'financed' => 'decimal:4',
            'markup_value' => 'decimal:4',
            'markup_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'start_date' => 'date',
            'first_due_date' => 'date',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
