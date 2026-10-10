<?php

namespace App\Exports;

use App\Domain\Schedule\Frequencies;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\PaymentMethods;
use Carbon\CarbonInterface;
use Generator;

/**
 * Everything a business keeps, sheet by sheet (Win Plan PP10): customers (removed ones too), contracts, instalments,
 * payments and every other ledger line, what was sold, investors and their entries. Read from the ACTIVE workspace
 * only, a few hundred rows at a time, in the language that is set. Amounts become numbers so Excel can add them up; this
 * is the only place they leave exact decimal strings, and only for the spreadsheet.
 */
final class WorkspaceData
{
    /** @return list<Sheet> */
    public static function sheets(): array
    {
        return [
            new Sheet('customers', __('Customers'), [__('Name'), __('Phone'), __('Second phone'), __('Email'), __('National ID'), __('Job or employer'), __('Address'), __('Notes'), __('Added on'), __('Removed on')], self::customers()),
            new Sheet('contracts', __('Contracts'), [__('Contract'), __('Customer'), __('Type'), __('Status'), __('What was sold'), __('Contract date'), __('Price'), __('Discount'), __('Down payment'), __('Financed'), __('Markup'), __('Total to repay'), __('Instalments'), __('How often'), __('Grace days'), __('What it cost you'), __('Tax in the price'), __('Funded by'), __('Notes'), __('Settled on'), __('Cancelled on')], self::contracts()),
            new Sheet('instalments', __('Instalments'), [__('Contract'), '#', __('Due date'), __('Amount'), __('Paid'), __('Still owed'), __('Status'), __('Paid on')], self::instalments()),
            new Sheet('payments', __('Payments'), [__('Date'), __('Contract'), __('Customer'), __('Type'), __('Method'), __('Amount'), __('Note'), __('Recorded by'), __('Recorded')], self::payments()),
            new Sheet('items', __('What was sold'), [__('Contract'), __('Item'), __('Quantity'), __('Serial or IMEI'), __('Price'), __('Cost')], self::items()),
            new Sheet('investors', __('Investors'), [__('Name'), __('The business’s own money'), __('Commission, %'), __('Commercial registration'), __('Notes'), __('Archived on')], self::investors()),
            new Sheet('investor_entries', __('Investor entries'), [__('Date'), __('Investor'), __('Type'), __('Contract'), __('Amount'), __('Note')], self::investorEntries()),
        ];
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function customers(): Generator
    {
        foreach (Customer::query()->withTrashed()->orderBy('created_at')->orderBy('id')->lazy(500) as $customer) {
            yield [$customer->name, $customer->phone, $customer->phone_secondary, $customer->email, $customer->national_id, $customer->job, $customer->address, $customer->notes, self::day($customer->created_at), self::day($customer->deleted_at)];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function contracts(): Generator
    {
        $types = ['scheduled' => __('In instalments'), 'cash' => __('Cash sale'), 'open' => __('Open account')];
        $statuses = ['active' => __('Active'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
        $query = Contract::query()->with(['customer' => fn ($q) => $q->withTrashed(), 'investor'])->orderBy('number');

        foreach ($query->lazy(500) as $contract) {
            yield [
                $contract->reference(), $contract->customer?->name, $types[$contract->type] ?? $contract->type, $statuses[$contract->status] ?? $contract->status,
                $contract->title, self::day($contract->start_date), self::number($contract->principal), self::number($contract->discount_amount), self::number($contract->down_payment),
                self::number($contract->financed), self::number($contract->markup_amount), self::number($contract->total), $contract->installment_count,
                $contract->isOpen() ? null : Frequencies::label($contract->frequency), $contract->grace_days, self::number($contract->cost_price), self::number($contract->tax_amount),
                $contract->investor?->name, $contract->notes, self::day($contract->settled_at), self::day($contract->cancelled_at),
            ];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function instalments(): Generator
    {
        $references = Contract::query()->get(['id', 'number', 'own_reference'])->mapWithKeys(fn (Contract $c) => [$c->id => $c->reference()]);
        $states = ['paid' => __('Paid'), 'superseded' => __('Moved to the open account'), 'overdue' => __('Overdue'), 'partial' => __('Part paid'), 'upcoming' => __('Upcoming')];

        foreach (Installment::query()->orderBy('contract_id')->orderBy('number')->lazy(500) as $installment) {
            yield [
                $references[$installment->contract_id] ?? null, $installment->number, self::day($installment->due_date), self::number($installment->amount),
                self::number($installment->paid_amount), self::number($installment->remaining()), $states[$installment->displayState()] ?? $installment->status, self::day($installment->paid_at),
            ];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function payments(): Generator
    {
        $types = ['payment' => __('Payment'), 'down_payment' => __('Down payment'), 'reversal' => __('Payment voided'), 'charge' => __('They took'), 'charge_reversal' => __('Taken back off the balance')];
        $query = Transaction::query()->with(['contract.customer' => fn ($q) => $q->withTrashed(), 'createdBy'])->orderBy('paid_at')->orderBy('created_at')->orderBy('id');

        foreach ($query->lazy(500) as $line) {
            yield [
                self::day($line->paid_at), $line->contract?->reference(), $line->contract?->customer?->name, $types[$line->type] ?? $line->type,
                in_array($line->type, ['payment', 'down_payment', 'reversal'], true) ? PaymentMethods::label($line->method) : null,
                self::number($line->amount), $line->note, $line->createdBy?->name, $line->created_at->format('Y-m-d H:i'),
            ];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function items(): Generator
    {
        $references = Contract::query()->get(['id', 'number', 'own_reference'])->mapWithKeys(fn (Contract $c) => [$c->id => $c->reference()]);

        foreach (ContractItem::query()->orderBy('contract_id')->orderBy('position')->lazy(500) as $item) {
            yield [$references[$item->contract_id] ?? null, $item->name, $item->quantity, $item->serial, self::number($item->price), self::number($item->cost)];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function investors(): Generator
    {
        foreach (Investor::query()->orderByDesc('is_main')->orderBy('name')->get() as $investor) {
            yield [$investor->name, $investor->is_main ? __('Yes') : __('No'), self::number($investor->commission_percent), $investor->commercial_registration, $investor->notes, self::day($investor->archived_at)];
        }
    }

    /** @return Generator<list<string|int|float|null>> */
    private static function investorEntries(): Generator
    {
        $names = Investor::query()->pluck('name', 'id');
        $references = Contract::query()->get(['id', 'number', 'own_reference'])->mapWithKeys(fn (Contract $c) => [$c->id => $c->reference()]);
        $types = [
            'deposit' => __('Money put in'), 'withdrawal' => __('Money taken out'), 'funding_out' => __('Funded a contract'), 'funding_back' => __('Back from a cancelled contract'),
            'principal_back' => __('Principal back from a payment'), 'profit_share' => __('Profit from a payment'), 'commission' => __('Commission'),
        ];

        foreach (InvestorEntry::query()->orderBy('occurred_on')->orderBy('created_at')->orderBy('id')->lazy(500) as $entry) {
            yield [self::day($entry->occurred_on), $names[$entry->investor_id] ?? null, $types[$entry->type] ?? $entry->type, $entry->contract_id ? ($references[$entry->contract_id] ?? null) : null, self::number($entry->amount), $entry->note];
        }
    }

    private static function day(?CarbonInterface $moment): ?string
    {
        return $moment?->format('Y-m-d');
    }

    private static function number(?string $amount): ?float
    {
        return $amount === null ? null : (float) Money::round($amount, 2);
    }
}
