<?php

namespace App\Actions;

use App\Domain\Investors\InvestorLedger;
use App\Domain\Investors\MainInvestor;
use App\Domain\Schedule\InvalidScheduleException;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Domain\Schedule\ScheduleResult;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Entitlements\LimitReached;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a contract for one of the workspace's customers and writes its instalments, in one transaction.
 * The one place contracts are created.
 */
final class CreateContract
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly ScheduleGenerator $generator,
        private readonly InvestorLedger $investors,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see App\Http\Requests\ContractRequest)
     *
     * @throws ModelNotFoundException when the customer is not one of this workspace's
     * @throws ValidationException when the schedule cannot be built
     * @throws FeatureLocked|FeatureUnavailable|LimitReached
     */
    public function handle(Tenant $tenant, array $data, ?User $by = null): Contract
    {
        return DB::transaction(fn () => $this->current->use($tenant, function () use ($tenant, $data, $by): Contract {
            // Serialise per workspace (see CreateCustomer): numbering and the limit check both depend on it.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            // The tenant scope and soft deletes make a foreign or deleted customer simply not found.
            $customer = Customer::query()->findOrFail($data['customer_id'] ?? null);

            Entitlements::for($tenant)->assertCanCreate(Feature::ActiveContracts);

            $type = $data['type'] ?? 'scheduled';
            $startDate = $data['start_date'] ?? today()->format('Y-m-d');
            if ($type === 'open') {
                return $this->openAccount($tenant, $customer, $data, $startDate, $by);
            }
            $graceDays = $type === 'cash' ? 0 : (int) ($data['grace_days'] ?? 0);
            $this->assertPlanAllowed($tenant, $type, $data, $graceDays);
            $schedule = $this->schedule($type, $data, $startDate);
            $investor = $this->investor($tenant, $data['investor_id'] ?? null);

            $contract = (new Contract)->forceFill([
                'customer_id' => $customer->id,
                'number' => (int) Contract::query()->max('number') + 1,
                'type' => $type,
                'status' => 'active',
                'principal' => Money::parse($data['principal']),
                'down_payment' => $type === 'cash' ? '0' : Money::parse($data['down_payment'] ?? '0'),
                'financed' => $schedule->financed,
                'markup_type' => $type === 'cash' ? 'none' : ($data['markup_type'] ?? 'none'),
                'markup_value' => $type === 'cash' ? '0' : Money::parse($data['markup_value'] ?? '0'),
                'markup_amount' => $schedule->markup,
                'total' => $schedule->total,
                'installment_count' => count($schedule->installments),
                'frequency' => $type === 'cash' ? 'monthly' : $data['frequency'],
                'grace_days' => $graceDays,
                'start_date' => $startDate,
                'first_due_date' => $schedule->installments[0]['due_date'],
                'notes' => $data['notes'] ?? null,
                'investor_id' => $investor->id,
                'created_by_user_id' => $by?->id,
            ]);
            $contract->save();
            // The amount financed leaves its investor's wallet the day the contract opens.
            $this->investors->fund($contract);

            // The down payment is money received today: it belongs in the ledger, not only on the contract.
            if ($type !== 'cash' && ! Money::isZero($contract->down_payment)) {
                (new Transaction)->forceFill([
                    'contract_id' => $contract->id,
                    'customer_id' => $customer->id,
                    'type' => 'down_payment',
                    'method' => 'cash',
                    'amount' => $contract->down_payment,
                    'paid_at' => Carbon::parse($startDate),
                    'created_by_user_id' => $by?->id,
                ])->save();
            }

            foreach ($schedule->installments as $row) {
                (new Installment)->forceFill([
                    'contract_id' => $contract->id,
                    'number' => $row['number'],
                    'due_date' => $row['due_date'],
                    // The last day it can be paid without being late; lateness everywhere reads this one date.
                    'grace_until' => Carbon::parse($row['due_date'])->addDays($graceDays)->format('Y-m-d'),
                    'amount' => $row['amount'],
                ])->save();
            }

            return $contract;
        }));
    }

    /**
     * An open contract (Win Plan PP4): no schedule, no price of its own; what the customer owes opening it is its first
     * line ("opening balance"), and a credit limit may warn when the tab grows past it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FeatureUnavailable|FeatureLocked
     */
    private function openAccount(Tenant $tenant, Customer $customer, array $data, string $startDate, ?User $by): Contract
    {
        Entitlements::for($tenant)->assertEnabled(Feature::OpenContracts);
        $investor = $this->investor($tenant, $data['investor_id'] ?? null);
        $limit = isset($data['credit_limit']) && $data['credit_limit'] !== '' ? Money::parse($data['credit_limit']) : null;

        $contract = (new Contract)->forceFill([
            'customer_id' => $customer->id,
            'number' => (int) Contract::query()->max('number') + 1,
            'type' => 'open',
            'status' => 'active',
            'principal' => '0', 'down_payment' => '0', 'financed' => '0',
            'markup_type' => 'none', 'markup_value' => '0', 'markup_amount' => '0', 'total' => '0',
            'installment_count' => 0,
            'frequency' => 'monthly',
            'grace_days' => 0,
            'start_date' => $startDate,
            'first_due_date' => $startDate,
            'credit_limit' => $limit,
            'notes' => $data['notes'] ?? null,
            'investor_id' => $investor->id,
            'created_by_user_id' => $by?->id,
        ]);
        $contract->save();

        $opening = isset($data['opening_balance']) && $data['opening_balance'] !== '' ? Money::add(Money::parse($data['opening_balance']), '0', 2) : '0';
        if (Money::isPositive($opening)) {
            $line = (new Transaction)->forceFill([
                'contract_id' => $contract->id,
                'customer_id' => $customer->id,
                'type' => 'charge',
                'method' => 'other',
                'amount' => $opening,
                'paid_at' => Carbon::parse($startDate),
                'note' => __('Opening balance'),
                'created_by_user_id' => $by?->id,
            ]);
            $line->save();
            $this->investors->fundCharge($line);
        }

        return $contract;
    }

    /**
     * Daily, quarterly, half-yearly or yearly plans, the shop's own dates, more than 120 instalments and grace days
     * belong to flexible schedules (Win Plan PP5). Without that feature a contract is what it always was.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FeatureUnavailable|FeatureLocked
     */
    private function assertPlanAllowed(Tenant $tenant, string $type, array $data, int $graceDays): void
    {
        if ($type === 'cash') {
            return;
        }

        $basic = in_array($data['frequency'] ?? '', ScheduleGenerator::BASIC_FREQUENCIES, true)
            && (int) ($data['installment_count'] ?? 0) <= ScheduleGenerator::BASIC_MAX_COUNT
            && $graceDays === 0;

        if (! $basic) {
            Entitlements::for($tenant)->assertEnabled(Feature::FlexibleSchedules);
        }
    }

    /**
     * Who funds the contract: the investor chosen (a partner needs the investors feature), or the business's own capital.
     *
     * @throws ValidationException|FeatureUnavailable|FeatureLocked
     */
    private function investor(Tenant $tenant, mixed $id): Investor
    {
        if ($id === null || $id === '') {
            return MainInvestor::for($tenant);
        }

        $investor = Investor::query()->active()->find($id)
            ?? throw ValidationException::withMessages(['investor_id' => __('Choose one of your investors.')]);
        if (! $investor->is_main) {
            Entitlements::for($tenant)->assertEnabled(Feature::Investors);
        }

        return $investor;
    }

    /** @param  array<string, mixed>  $data */
    private function schedule(string $type, array $data, string $startDate): ScheduleResult
    {
        $request = match ($type) {
            // A cash sale is paid in full on the day: one instalment, no markup.
            'cash' => new ScheduleRequest((string) ($data['principal'] ?? ''), '0', 'none', '0', 1, 'monthly', $startDate),
            'scheduled' => ScheduleRequest::fromArray([
                'principal' => $data['principal'] ?? '',
                'down_payment' => $data['down_payment'] ?? '0',
                'markup_type' => $data['markup_type'] ?? 'none',
                'markup_value' => $data['markup_value'] ?? '0',
                'count' => $data['installment_count'] ?? 0,
                'frequency' => $data['frequency'] ?? '',
                'first_due_date' => $data['first_due_date'] ?? '',
                'custom_schedule' => $data['custom_schedule'] ?? null,
            ]),
            default => throw ValidationException::withMessages(['type' => __('The contract type must be scheduled or cash.')]),
        };

        try {
            return $this->generator->generate($request);
        } catch (InvalidScheduleException $e) {
            throw ValidationException::withMessages(['schedule' => $e->getMessage()]);
        }
    }
}
