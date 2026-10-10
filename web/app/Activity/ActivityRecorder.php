<?php

namespace App\Activity;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the everyday work of a business to the audit log, whichever way it was done (web, app, API): customers added,
 * changed, removed and brought back; contracts opened; payments recorded and voided; charges on open contracts; products.
 * Other actions (cancelling a contract, investors, the team, settings) already write their own entries.
 *
 * Only what the activity log needs is kept: a customer's name, a contract's number, an amount, and the NAMES of the
 * fields that changed, never their values (so a national ID never reaches the log). A throw-away demo account keeps no
 * log.
 */
final class ActivityRecorder
{
    /** Columns that change on their own, never by someone's choice. */
    private const QUIET = ['updated_at', 'created_at', 'deleted_at', 'created_by_user_id'];

    public static function register(): void
    {
        Customer::created(fn (Customer $customer) => self::record('customer.created', $customer, ['name' => $customer->name]));
        Customer::updated(function (Customer $customer): void {
            // A soft delete or a restore is an update too; those have their own entries.
            $fields = array_values(array_diff(array_keys($customer->getChanges()), self::QUIET));
            if ($fields !== []) {
                self::record('customer.updated', $customer, ['name' => $customer->name, 'fields' => $fields]);
            }
        });
        Customer::deleted(fn (Customer $customer) => self::record('customer.deleted', $customer, ['name' => $customer->name]));
        Customer::restored(fn (Customer $customer) => self::record('customer.restored', $customer, ['name' => $customer->name]));

        Contract::created(fn (Contract $contract) => self::record('contract.created', $contract, ['reference' => $contract->reference(), 'type' => $contract->type]));

        Transaction::created(function (Transaction $line): void {
            $action = match ($line->type) {
                'payment', 'down_payment' => 'payment.recorded',
                'reversal' => 'payment.voided',
                'charge' => 'charge.recorded',
                'charge_reversal' => 'charge.voided',
                default => null,
            };
            if ($action === null) {
                return;
            }

            $amount = Money::add($line->amount, '0', 2);
            self::record($action, $line, [
                'reference' => Contract::query()->whereKey($line->contract_id)->first()?->reference(),
                'amount' => Money::isNegative($amount) ? Money::sub('0', $amount, 2) : $amount,
                'type' => $line->type,
            ], $line->created_by_user_id);
        });

        Product::created(fn (Product $product) => self::record('product.created', $product, ['name' => $product->name]));
        Product::updated(function (Product $product): void {
            $fields = array_values(array_diff(array_keys($product->getChanges()), self::QUIET));
            if ($fields === []) {
                return;
            }
            $archived = in_array('archived_at', $fields, true) && $product->archived_at !== null;
            self::record($archived ? 'product.archived' : 'product.updated', $product, ['name' => $product->name, 'fields' => $fields]);
        });
    }

    /** @param  array<string, mixed>  $changes */
    private static function record(string $action, Model $subject, array $changes, ?string $userId = null): void
    {
        if (app(CurrentTenant::class)->get()?->is_demo) {
            return;
        }

        Audit::record($action, $subject, array_filter($changes, fn (mixed $value) => $value !== null), (string) $subject->getAttribute('tenant_id'), $userId ?? auth()->id());
    }
}
