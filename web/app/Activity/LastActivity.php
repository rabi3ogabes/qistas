<?php

namespace App\Activity;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each customer's last activity (Win Plan PP12): when they were added, then each contract opened and each line
 * written on their account. Written straight to the column, so it neither changes the customer's own "updated" time nor
 * shows in the activity log, and sorting a long list by it stays one indexed column.
 */
final class LastActivity
{
    public static function register(): void
    {
        Customer::creating(function (Customer $customer): void {
            $customer->last_activity_at ??= now();
        });

        Contract::created(fn (Contract $contract) => self::touch(DB::table('customers')->where('id', $contract->customer_id)));

        Transaction::created(fn (Transaction $line) => self::touch(DB::table('customers')->whereIn('id', DB::table('contracts')->select('customer_id')->where('id', $line->contract_id))));
    }

    private static function touch(Builder $customers): void
    {
        $customers->update(['last_activity_at' => now()]);
    }
}
