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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone the business lends to. Belongs to exactly one workspace. Deleting is soft: the row stays for the
 * ledger, but the customer no longer counts against the plan limit.
 *
 * The workspace and the creator are set by trusted code only (see BelongsToTenant and App\Actions\CreateCustomer).
 */
#[Fillable(['name', 'phone', 'phone_secondary', 'email', 'national_id', 'address', 'notes'])]
#[Hidden(['national_id'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

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
        return ['national_id' => 'encrypted'];
    }
}
