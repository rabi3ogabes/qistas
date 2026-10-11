<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AmendContract;
use App\Actions\ArchiveContract;
use App\Actions\CancelContract;
use App\Actions\ConvertToOpen;
use App\Actions\CreateContract;
use App\Actions\RecordCharge;
use App\Actions\RecordPayment;
use App\Domain\Contracts\SerialCheck;
use App\Domain\LateFees\LateFees;
use App\Domain\Ledger\LedgerLines;
use App\Domain\Ledger\OpenAccount;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Settlement\EarlySettlementQuote;
use App\Domain\Settlement\QuoteChanged;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\CancelContractRequest;
use App\Http\Requests\ChargeRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Resources\ContractResource;
use App\Http\Resources\TransactionResource;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Installment;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The workspace's contracts. Thin: the rules live in the actions, the policy and the requests. */
final class ContractController
{
    public function __construct(private readonly CurrentTenant $current, private readonly ContractProgress $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Contract::class);

        $status = $request->query('status');
        $view = is_string($status) && in_array($status, Contract::VIEWS, true) ? $status : 'active';

        $page = Contract::query()->with('customer')->inView($view)->search((string) $request->query('q', ''))
            ->orderByDesc('number')->paginate(PerPage::of($request))->withQueryString();

        $this->attachProgress($page->getCollection());

        return ContractResource::collection($page);
    }

    public function store(ContractRequest $request, CreateContract $create): JsonResponse
    {
        $contract = $create->handle($this->current->get(), $request->validated(), $request->user());
        // A serial already on another running contract warns and never refuses (Win Plan PP7).
        $warnings = SerialCheck::warnings($contract);

        return $this->one($contract)->additional($warnings === [] ? [] : ['meta' => ['warnings' => $warnings]])->response()->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return $this->one($contract);
    }

    /** Puts a settled or cancelled contract away (Win Plan PP12), or brings it back. */
    public function archive(Request $request, Contract $contract, ArchiveContract $archive): ContractResource
    {
        Gate::authorize('archive', $contract);

        return $this->one($archive->handle($contract, true, $request->user()));
    }

    public function unarchive(Request $request, Contract $contract, ArchiveContract $archive): ContractResource
    {
        Gate::authorize('archive', $contract);

        return $this->one($archive->handle($contract, false, $request->user()));
    }

    public function cancel(CancelContractRequest $request, Contract $contract, CancelContract $cancel): ContractResource
    {
        $cancel->handle($contract, $request->reason(), $request->user());

        return $this->one($contract->refresh());
    }

    /**
     * "They took" on an open contract (Win Plan PP4). The answer says whether the tab is now past its credit limit,
     * which warns and never refuses; a retry with the same key answers with the first line, and 200 instead of 201.
     */
    public function charge(ChargeRequest $request, Contract $contract, RecordCharge $record): JsonResponse
    {
        Gate::authorize('view', $contract);
        Gate::authorize('create', Transaction::class);
        $data = $request->validated();

        $result = $record->handle($contract, $data['amount'], $data['tag'] ?? null, $data['note'] ?? null, $data['idempotency_key'] ?? null, $request->user(), $request->chargedAt());
        $line = $result['transaction']->load('createdBy');

        return (new TransactionResource($line))->additional(['meta' => ['balance' => $result['balance'], 'over_credit_limit' => $result['over_credit_limit']]])
            ->response()->setStatusCode($line->wasRecentlyCreated ? 201 : 200);
    }

    /** Turns a scheduled or cash contract into an open one; with `preview=1`, only says what would happen. */
    public function convert(Request $request, Contract $contract, ConvertToOpen $convert): JsonResponse
    {
        Gate::authorize('convert', $contract);

        if ($request->boolean('preview')) {
            return response()->json(['data' => $convert->handle($contract, $request->user(), preview: true)]);
        }

        $outcome = $convert->handle($contract, $request->user());

        return response()->json(['data' => ['conversion' => $outcome, 'contract' => $this->one($contract->refresh())->resolve($request)]]);
    }

    /** One contract with everything a screen needs: customer, schedule, money received, what is owed. */
    /** What settling early costs today (Win Plan PP6): the payoff and the rebate, nothing written. */
    public function earlySettlement(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);
        $this->assertOn(Feature::EarlySettlement);
        $data = $request->validate(self::rebateRules());
        if ($contract->status !== 'active' || $contract->isOpen()) {
            throw ValidationException::withMessages(['contract' => __('Only a running contract with a schedule can be settled early.')]);
        }

        try {
            $quote = EarlySettlementQuote::for($contract, Installment::query()->where('contract_id', $contract->id)->get(), $data['rebate_type'] ?? 'none', $data['rebate_value'] ?? '0', LateFees::owed($contract));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rebate_value' => __('A rebate is between 0 and 100 percent, or an amount of zero or more.')]);
        }

        return response()->json(['data' => $quote->toArray()]);
    }

    /**
     * Settles early at the payoff the person was shown (Win Plan PP6). A payoff that has gone stale is refused with 409
     * and the new quote; safe to retry with an Idempotency-Key.
     */
    public function settleEarly(Request $request, Contract $contract, RecordPayment $record): JsonResponse
    {
        Gate::authorize('settle', $contract);
        $this->assertOn(Feature::EarlySettlement);
        $data = $request->validate(self::rebateRules() + [
            'payoff' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'method' => ['required', 'string', Rule::in(config('qistas.payment_methods'))],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null;
        if ($paidAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['paid_at'])) {
            $paidAt = $paidAt->isToday() ? now() : $paidAt->endOfDay();
        }

        try {
            $result = $record->settle(
                $contract, $data['rebate_type'] ?? 'none', $data['rebate_value'] ?? '0', $data['payoff'], $data['method'],
                trim((string) $request->header('Idempotency-Key')) ?: null, $request->user(), $data['note'] ?? null, $paidAt,
            );
        } catch (QuoteChanged $changed) {
            return response()->json([
                'error' => ['code' => 'quote_changed', 'message' => __('The figure has changed since it was shown: money came in meanwhile. Here is the new one.')],
                'data' => $changed->quote->toArray(),
            ], 409);
        }

        return $this->one($contract->refresh())->response()->setStatusCode($result->wasRecentlyCreated ? 201 : 200);
    }

    /** What a change to the plan would do, writing nothing (Win Plan PP6). Anyone who writes may look. */
    public function previewAmendment(Request $request, Contract $contract, AmendContract $amend): JsonResponse
    {
        Gate::authorize('view', $contract);
        Gate::authorize('create', Contract::class);
        $this->assertOn(Feature::Reschedule);

        return response()->json(['data' => $amend->preview($contract, $request->validate(self::amendmentRules()))]);
    }

    /** Confirms a change to the plan, with its reason (Win Plan PP6): owners and managers only. */
    public function amend(Request $request, Contract $contract, AmendContract $amend): JsonResponse
    {
        Gate::authorize('amend', $contract);
        $this->assertOn(Feature::Reschedule);
        $data = $request->validate(self::amendmentRules() + ['reason' => ['required', 'string', 'max:500']]);

        $amendment = $amend->handle($contract, $data, $request->user());

        return response()->json(['data' => [
            'amendment' => [
                'id' => $amendment->id,
                'type' => $amendment->type,
                'reason' => $amendment->reason,
                'old_schedule' => $amendment->old_schedule,
                'new_schedule' => $amendment->new_schedule,
                'created_at' => $amendment->created_at->toIso8601String(),
            ],
            'contract' => $this->one($contract->refresh())->resolve($request),
        ]], 201);
    }

    /** @return array<string, list<mixed>> */
    private static function rebateRules(): array
    {
        return [
            'rebate_type' => ['sometimes', Rule::in(EarlySettlementQuote::REBATE_TYPES)],
            'rebate_value' => ['sometimes', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function amendmentRules(): array
    {
        return [
            'type' => ['required', Rule::in(ContractAmendment::TYPES)],
            'count' => ['nullable', 'integer'],
            'amount' => ['nullable', 'string', 'max:20'],
            'first_due_date' => ['nullable', 'date_format:Y-m-d'],
            'frequency' => ['nullable', Rule::in(array_keys(ScheduleGenerator::FREQUENCIES))],
            'installment_id' => ['nullable', 'uuid'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function assertOn(Feature $feature): void
    {
        Entitlements::for($this->current->get() ?? abort(404))->assertEnabled($feature);
    }

    private function one(Contract $contract): ContractResource
    {
        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get();
        $lines = Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get();
        $after = $contract->isOpen() ? OpenAccount::runningBalances($contract) : [];
        LedgerLines::withReversals($lines)->each(fn (Transaction $line) => $line->setAttribute('balance_after', $after[$line->id] ?? null));

        $contract->load(['customer', 'investor', 'items', 'adjustments', 'amendments.approvedBy', 'lateFees.installment', 'lateFees.waivedBy']);
        $this->attachProgress(collect([$contract]));
        $contract->setAttribute('paid', $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->paid_amount), '0'));
        $contract->setRelation('installments', $installments);
        $contract->setRelation('transactions', $lines);

        return new ContractResource($contract);
    }

    /** @param  Collection<int, Contract>  $contracts */
    private function attachProgress($contracts): void
    {
        $details = $this->progress->forContracts($contracts->pluck('id')->all());

        $contracts->each(fn (Contract $contract) => $contract->setAttribute('progress', $details[$contract->id]));
    }
}
