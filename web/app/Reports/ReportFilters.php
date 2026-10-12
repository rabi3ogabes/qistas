<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Investor;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a report covers (Win Plan RP14): a period for what happened (profit, collection), and optionally one investor
 * and one product, which narrow every report to the contracts they fund or that sold the product. The investor and
 * the product must be the active workspace's.
 */
final readonly class ReportFilters
{
    public const PERIODS = ['this_month', 'last_3_months', 'last_12_months', 'this_year', 'custom'];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $period = 'last_12_months',
        public ?string $investorId = null,
        public ?string $productId = null,
    ) {}

    /**
     * The filters a request asks for: `period` (default the last 12 months, whole months), `from` and `to` for a
     * custom period, `investor_id`, `product_id`.
     *
     * @throws ValidationException naming the field at fault
     */
    public static function fromRequest(Request $request, ?CarbonInterface $today = null): self
    {
        $data = Validator::make($request->query(), [
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'investor_id' => ['nullable', 'uuid', fn (string $a, mixed $v, \Closure $fail) => Investor::query()->whereKey($v)->exists() ? null : $fail(__('Choose one of your investors.'))],
            'product_id' => ['nullable', 'uuid', fn (string $a, mixed $v, \Closure $fail) => Product::query()->whereKey($v)->exists() ? null : $fail(__('Choose one of your products.'))],
        ], [
            'to.after_or_equal' => __('The period must end on or after the day it starts.'),
        ])->validate();

        $today = CarbonImmutable::parse(($today ?? today())->format('Y-m-d'));
        $period = $data['period'] ?? 'last_12_months';
        [$from, $to] = match ($period) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'last_3_months' => [$today->subMonthsNoOverflow(2)->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
            'custom' => [CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to'])],
            default => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };

        return new self($from, $to, $period, $data['investor_id'] ?? null, $data['product_id'] ?? null);
    }

    /**
     * The workspace's contracts these filters keep (any status; each report picks the ones it counts).
     *
     * @return Builder<Contract>
     */
    public function contracts(): Builder
    {
        return Contract::query()
            ->when($this->investorId !== null, fn (Builder $q) => $q->where('investor_id', $this->investorId))
            ->when($this->productId !== null, fn (Builder $q) => $q->whereHas('items', fn (Builder $items) => $items->where('product_id', $this->productId)));
    }

    /** @return array<string, string> the query that asks for these filters again (for links and the export) */
    public function query(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->period === 'custom' ? $this->from->format('Y-m-d') : null,
            'to' => $this->period === 'custom' ? $this->to->format('Y-m-d') : null,
            'investor_id' => $this->investorId,
            'product_id' => $this->productId,
        ]);
    }
}
