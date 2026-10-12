<?php

namespace App\Import;

use App\Actions\CreateContract;
use App\Actions\CreateCustomer;
use App\Actions\RecordPayment;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Confirms an import (Win Plan K9, decision D8): the trial run is made again against the books as they are now, and
 * every row without a problem is brought in, all at once or not at all. A customer already on the books gets the
 * contract instead of a second customer. A contract with a plan gets what was already paid as one payment dated today
 * ("Paid before Qistas"); a balance with no plan becomes an open contract's opening line. Confirming again changes
 * nothing: the import answers with what it did the first time. The cells are dropped once it is done.
 */
final class ApplyImport
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly CreateCustomer $customers,
        private readonly CreateContract $contracts,
        private readonly RecordPayment $payments,
    ) {}

    public function handle(Import $import, User $by): Import
    {
        $tenant = $import->tenant;

        return $this->current->use($tenant, fn () => DB::transaction(function () use ($import, $tenant, $by): Import {
            $locked = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDone()) {
                return $locked;
            }
            Entitlements::for($tenant)->assertEnabled(Feature::Import);

            $plan = ImportPlan::make($tenant, $locked->headers, $locked->cells, $locked->mapping);
            $rows = [];
            foreach ($plan['rows'] as $row) {
                if ($row['problems'] === [] && $row['action'] !== 'skip') {
                    $customer = $row['action'] === 'existing'
                        ? Customer::query()->findOrFail($row['customer_id'])
                        : $this->customers->handle($tenant, array_filter($row['customer'], fn ($value) => $value !== null), $by);
                    $row['customer_id'] = $customer->id;

                    if ($row['contract'] !== null) {
                        $this->contract($tenant, $customer, $row['contract'], $by);
                    }
                }

                unset($row['customer']);
                $rows[] = $row;
            }

            $locked->forceFill([
                'status' => 'done',
                'cells' => [],
                'rows' => $rows,
                'summary' => $plan['summary'],
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $by->id,
            ])->save();

            Audit::record('import.confirmed', $locked, array_intersect_key($plan['summary'], array_flip(['customers', 'contracts', 'skipped'])), $tenant->id, $by->id);

            return $locked;
        }));
    }

    /** @param  array<string, mixed>  $plan */
    private function contract(Tenant $tenant, Customer $customer, array $plan, User $by): void
    {
        if ($plan['type'] === 'open') {
            $this->contracts->handle($tenant, [
                'customer_id' => $customer->id,
                'type' => 'open',
                'opening_balance' => $plan['balance'],
                'start_date' => $plan['start'],
                'notes' => $plan['item'],
            ], $by);

            return;
        }

        $contract = $this->contracts->handle($tenant, [
            'customer_id' => $customer->id,
            'type' => 'scheduled',
            'principal' => $plan['total'],
            'down_payment' => '0',
            'markup_type' => 'none',
            'markup_value' => '0',
            'installment_count' => $plan['installments'],
            'frequency' => 'monthly',
            'start_date' => $plan['start'],
            'first_due_date' => $plan['first_due'],
            'notes' => $plan['item'],
        ], $by);

        if (Money::isPositive($plan['paid'])) {
            $this->payments->handle($contract, $plan['paid'], 'other', by: $by, note: __('Paid before Qistas'), paidAt: now());
        }
    }
}
