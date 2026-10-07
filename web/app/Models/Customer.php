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
