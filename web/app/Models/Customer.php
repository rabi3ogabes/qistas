<?php

namespace App\Models;

use App\Support\Digits;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Someone the business lends to. Belongs to exactly one workspace. Deleting is soft: the row stays for the
 * ledger, but the customer no longer counts against the plan limit.
 *
 * The workspace and the creator are set by trusted code only (see BelongsToTenant and App\Actions\CreateCustomer).
 *
 * @property Carbon|null $pinned_at kept at the top of every list (Win Plan PP12)
 * @property Carbon|null $last_activity_at added, or a contract or ledger line written for them; see App\Activity\LastActivity
 */
#[Fillable(['name', 'phone', 'phone_secondary', 'email', 'national_id', 'address', 'notes', 'job'])]
#[Hidden(['national_id'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** How a list of customers can be ordered (Win Plan PP12); pinned customers always come first. */
    public const SORTS = ['name', 'balance', 'next_due', 'activity'];

    /** What a customer owes, worked out the same way as App\Reports\CustomerBalances, for ordering a list by it. */
    private const OWED_SQL = '(SELECT COALESCE(SUM(i.amount - i.paid_amount), 0) FROM installments i JOIN contracts c ON c.id = i.contract_id'
        ." WHERE c.customer_id = customers.id AND c.status <> 'cancelled' AND i.status <> 'superseded')"
        ." + (SELECT COALESCE(SUM(CASE WHEN t.type IN ('charge', 'charge_reversal') THEN t.amount ELSE -t.amount END), 0)"
        .' FROM transactions t JOIN contracts oc ON oc.id = t.contract_id'
        ." WHERE oc.customer_id = customers.id AND oc.type = 'open' AND oc.status <> 'cancelled'"
        ." AND (t.type IN ('charge', 'charge_reversal') OR (t.type IN ('payment', 'reversal')"
        .' AND NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id = t.id))))';

    /** The next instalment still to pay on a running contract. */
    private const NEXT_DUE_SQL = '(SELECT MIN(n.due_date) FROM installments n JOIN contracts nc ON nc.id = n.contract_id'
        ." WHERE nc.customer_id = customers.id AND nc.status = 'active' AND n.status NOT IN ('paid', 'superseded'))";

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'customer_tags')->withPivot('tenant_id')->orderByRaw('LOWER(tags.name)');
    }

    /**
     * Puts the customer under exactly these tags of the same business.
     *
     * @param  list<string>  $tagIds
     */
    public function syncTags(array $tagIds): void
    {
        $this->tags()->sync(array_fill_keys($tagIds, ['tenant_id' => $this->tenant_id]));
    }

    /**
     * Pinned customers first, then by name, by what they owe (most first), by their next due date (soonest first, those
     * with nothing due last) or by their last activity (newest first); the name settles any tie.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSorted(Builder $query, string $sort): Builder
    {
        $query->select('customers.*')->orderByRaw('CASE WHEN customers.pinned_at IS NULL THEN 1 ELSE 0 END');

        match ($sort) {
            'balance' => $query->selectRaw(self::OWED_SQL.' AS sort_owed')->orderByRaw('sort_owed DESC'),
            'next_due' => $query->selectRaw(self::NEXT_DUE_SQL.' AS sort_next_due')->orderByRaw('sort_next_due ASC NULLS LAST'),
            'activity' => $query->orderByRaw('customers.last_activity_at DESC NULLS LAST'),
            default => null,
        };

        return $query->orderByRaw('LOWER(customers.name)')->orderBy('customers.id');
    }

    /** The national ID with all but the last three digits hidden, for screens; never the full number. */
    public function maskedNationalId(): ?string
    {
        $id = (string) $this->national_id;
        $length = mb_strlen($id);

        if ($length === 0) {
            return null;
        }
        if ($length <= 3) {
            return '••';
        }

        return str_repeat('•', max(2, min(6, $length - 3))).mb_substr($id, -3);
    }

    /** A link that starts a phone call: only digits and a leading + are kept. */
    public function telUrl(): string
    {
        return 'tel:'.preg_replace('/[^\d+]/', '', $this->phone);
    }

    /** A WhatsApp chat link, possible only when the number carries its country code (+ or 00 prefix). */
    public function whatsappUrl(): ?string
    {
        $phone = trim($this->phone);

        $international = match (true) {
            str_starts_with($phone, '+') => substr($phone, 1),
            str_starts_with($phone, '00') => substr($phone, 2),
            default => null,
        };
        $digits = $international === null ? '' : (string) preg_replace('/\D/', '', $international);

        return strlen($digits) >= 7 ? "https://wa.me/{$digits}" : null;
    }

    /**
     * Name, either phone number or email contains the text. Phone numbers typed with Arabic-Indic digits
     * match their ASCII form.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim(Digits::toAscii((string) $term));

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            foreach (['name', 'phone', 'phone_secondary', 'email'] as $column) {
                $q->orWhereLike($column, "%{$term}%");
            }
        });
    }

    protected function casts(): array
    {
        // National IDs are encrypted at rest with the application key and are not searchable.
        return ['national_id' => 'encrypted', 'pinned_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }
}
