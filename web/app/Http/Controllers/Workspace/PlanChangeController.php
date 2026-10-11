<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\AmendContract;
use App\Actions\RecordCustomerPayment;
use App\Actions\RecordPayment;
use App\Domain\Schedule\Frequencies;
use App\Domain\Settlement\EarlySettlementQuote;
use App\Domain\Settlement\QuoteChanged;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use App\Support\Format;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Win Plan PP6 on the web: settle early with the payoff in front of you, change the plan with the old and new schedules
 * side by side, and take one payment across a customer's contracts. Thin: RecordPayment, AmendContract and
 * RecordCustomerPayment decide.
 */
final class PlanChangeController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function settleShow(Request $request, Contract $contract): View
    {
        Gate::authorize('settle', $contract);
        $this->assertOn(Feature::EarlySettlement);
        abort_unless($contract->status === 'active' && ! $contract->isOpen(), 404);

        $type = in_array($request->query('rebate_type'), EarlySettlementQuote::REBATE_TYPES, true) ? (string) $request->query('rebate_type') : 'none';
        $value = is_string($request->query('rebate_value')) && preg_match('/^\d{1,14}(\.\d{1,4})?$/', $request->query('rebate_value')) ? $request->query('rebate_value') : '0';
        try {
            $quote = EarlySettlementQuote::for($contract, Installment::query()->where('contract_id', $contract->id)->get(), $type, $value);
        } catch (InvalidArgumentException) {
            $quote = EarlySettlementQuote::for($contract, Installment::query()->where('contract_id', $contract->id)->get());
        }

        return view('app.contracts.settle', ['contract' => $contract->load('customer'), 'quote' => $quote, 'currency' => $this->current->get()?->currency]);
    }

    public function settleStore(Request $request, Contract $contract, RecordPayment $record): RedirectResponse
    {
        Gate::authorize('settle', $contract);
        $this->assertOn(Feature::EarlySettlement);
        $data = $request->validate([
            'rebate_type' => ['nullable', Rule::in(EarlySettlementQuote::REBATE_TYPES)],
            'rebate_value' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'payoff' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(config('qistas.payment_methods'))],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:\-]{1,100}$/'],
        ]);

        try {
            $record->settle($contract, $data['rebate_type'] ?? 'none', $data['rebate_value'] ?? '0', $data['payoff'], $data['method'], $data['idempotency_key'] ?? null, $request->user(), $data['note'] ?? null);
        } catch (QuoteChanged $changed) {
            return back()->withInput()->withErrors(['payoff' => __('Money came in since this figure was shown. The payoff is now :amount.', [
                'amount' => Format::money($changed->quote->payoff, (string) $this->current->get()?->currency),
            ])]);
        }

        return redirect()->route('app.contracts.show', $contract)->with('status', __('Settled early. The contract is paid in full.'));
    }

    public function planShow(Request $request, Contract $contract, AmendContract $amend): View
    {
        Gate::authorize('amend', $contract);
        $this->assertOn(Feature::Reschedule);
        abort_unless($contract->status === 'active' && $contract->type === 'scheduled', 404);

        $change = $request->only(['type', 'count', 'amount', 'first_due_date', 'installment_id', 'due_date']);
        $preview = null;
        $errors = [];
        if (in_array($change['type'] ?? null, ContractAmendment::TYPES, true)) {
            try {
                $preview = $amend->preview($contract, $this->typed($change));
            } catch (ValidationException $e) {
                $errors = $e->errors();
            }
        }

        $untouched = Installment::query()->where('contract_id', $contract->id)->where('status', 'pending')->where('paid_amount', 0)->orderBy('due_date')->orderBy('number')->get();

        return view('app.contracts.plan', [
            'contract' => $contract->load('customer'),
            'change' => $change,
            'preview' => $preview,
            'previewErrors' => $errors,
            'untouched' => $untouched,
            'frequency' => Frequencies::label($contract->frequency),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function planStore(Request $request, Contract $contract, AmendContract $amend): RedirectResponse
    {
        Gate::authorize('amend', $contract);
        $this->assertOn(Feature::Reschedule);
        $data = $request->validate([
            'type' => ['required', Rule::in(ContractAmendment::TYPES)],
            'count' => ['nullable', 'integer'],
            'amount' => ['nullable', 'string', 'max:20'],
            'first_due_date' => ['nullable', 'date_format:Y-m-d'],
            'installment_id' => ['nullable', 'uuid'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => __('Say why the plan is changing.')]);

        $amend->handle($contract, $this->typed($data), $request->user());

        return redirect()->route('app.contracts.show', $contract)->with('status', __('The plan is changed. The old instalments are kept in the history.'));
    }

    public function customerPaymentShow(Customer $customer, ContractProgress $progress): View
    {
        Gate::authorize('view', $customer);
        Gate::authorize('create', Transaction::class);
        $this->assertOn(Feature::PaymentTargeting);

        // The contracts the payment can reach: running, with a schedule (an open account takes its own payments).
        $contracts = Contract::query()->where('customer_id', $customer->id)->where('status', 'active')->where('type', '!=', 'open')->orderBy('number')->get();
        $rows = $progress->forContracts($contracts->pluck('id')->all());

        return view('app.customers.payment', [
            'customer' => $customer,
            'contracts' => $contracts,
            'progress' => $rows,
            'suggested' => $this->suggestedAmount($rows),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    /**
     * What the form starts at: each contract's next instalment that is already due, or, when none is yet, the soonest
     * one; a contract's own payment form starts at its next instalment the same way, and so does the app. Never the
     * whole balance, which one tap could record by mistake.
     *
     * @param  array<string, array{next: Installment|null}>  $progress
     */
    private function suggestedAmount(array $progress): string
    {
        $next = collect($progress)->pluck('next')->filter()->sortBy(fn (Installment $i) => $i->due_date->format('Y-m-d'))->values();
        $due = $next->filter(fn (Installment $i) => ! $i->due_date->isAfter(today()))
            ->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->remaining(), 2), '0.00');
        if (Money::isPositive($due)) {
            return $due;
        }

        return $next->isEmpty() ? '' : Money::add($next->first()->remaining(), '0', 2);
    }

    public function customerPaymentStore(Request $request, Customer $customer, RecordCustomerPayment $record): RedirectResponse
    {
        Gate::authorize('view', $customer);
        Gate::authorize('create', Transaction::class);
        $this->assertOn(Feature::PaymentTargeting);
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'method' => ['required', Rule::in(config('qistas.payment_methods'))],
            'paid_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:\-]{1,100}$/'],
        ]);
        $paidAt = isset($data['paid_at']) ? (Carbon::parse($data['paid_at'])->isToday() ? now() : Carbon::parse($data['paid_at'])->endOfDay()) : null;

        $batch = $record->handle($customer, $data['amount'], $data['method'], $data['idempotency_key'] ?? null, $request->user(), $data['note'] ?? null, $paidAt);

        return redirect()->route('app.customers.show', $customer)->with('status', __('Payment of :amount recorded across :count contracts.', [
            'amount' => Format::money((string) $batch->amount, (string) $this->current->get()?->currency),
            'count' => Transaction::query()->where('batch_id', $batch->id)->count(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function typed(array $change): array
    {
        $blank = fn (string $key) => ($change[$key] ?? '') === '' ? null : $change[$key];

        return [
            'type' => (string) ($change['type'] ?? ''),
            'count' => $blank('count') === null ? null : (int) $change['count'],
            'amount' => $blank('amount'),
            'first_due_date' => $blank('first_due_date'),
            'installment_id' => $blank('installment_id'),
            'due_date' => $blank('due_date'),
            'reason' => $change['reason'] ?? null,
        ];
    }

    private function assertOn(Feature $feature): void
    {
        Entitlements::for($this->current->get() ?? abort(403))->assertEnabled($feature);
    }
}
