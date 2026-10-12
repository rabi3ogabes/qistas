<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Exports\Workbook;
use App\Models\Investor;
use App\Models\Product;
use App\Reports\Ageing;
use App\Reports\CashForecast;
use App\Reports\Charts\BarChart;
use App\Reports\ProfitReport;
use App\Reports\ReportFilters;
use App\Reports\ReportsWorkbook;
use App\Support\Locale;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The reports page (Win Plan K8, RP14, RP15, CT27): the cash-flow forecast, ageing and profit as money comes in, with
 * charts drawn by the server and an Excel file of the same figures. Everyone in the workspace but collectors; a Free
 * workspace is shown what Pro would give it, with the way to upgrade.
 */
final class ReportController
{
    /** Contracts listed under ageing on the page; the export has them all. */
    public const AGEING_ROWS = 15;

    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, CashForecast $forecasts, Ageing $ageings, ProfitReport $profits): View
    {
        Gate::authorize('view-reports');
        $tenant = $this->current->get() ?? abort(403);
        try {
            Entitlements::for($tenant)->assertEnabled(Feature::AdvancedReports);
        } catch (FeatureLocked $e) {
            return view('app.reports', ['locked' => $e->payload()]);
        } catch (FeatureUnavailable) {
            abort(404);
        }

        $filters = ReportFilters::fromRequest($request);
        $forecast = $forecasts->for($filters);
        $ageing = $ageings->for($filters);
        $profit = $profits->for($filters);
        $currency = (string) $tenant->currency;
        $bucketLabels = self::bucketLabels();

        return view('app.reports', [
            'locked' => null,
            'filters' => $filters,
            'forecast' => $forecast,
            'ageing' => $ageing,
            'ageingRows' => array_slice($ageing['contracts'], 0, self::AGEING_ROWS),
            'profit' => $profit,
            'currency' => $currency,
            'bucketLabels' => $bucketLabels,
            'investors' => Gate::allows('viewAny', Investor::class) && Entitlements::for($tenant)->check(Feature::Investors)->enabled()
                ? Investor::query()->orderByDesc('is_main')->orderBy('name')->get() : collect(),
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'forecastChart' => BarChart::columns(
                array_map(fn (array $week) => ['label' => CarbonImmutable::parse($week['from'])->translatedFormat('j M'), 'value' => $week['amount']], $forecast['weeks']),
                __('Expected each week for the next 13 weeks'), $currency),
            'ageingChart' => BarChart::rows(
                array_map(fn (string $bucket) => ['label' => $bucketLabels[$bucket], 'value' => $ageing['buckets'][$bucket], 'tone' => $bucket === 'current' ? 'quiet' : ($bucket === 'days_1_30' ? 'warn' : 'danger')], Ageing::BUCKETS),
                __('What is owed, by how late it is'), $currency),
            'profitChart' => BarChart::columns(
                array_map(fn (array $m) => ['label' => CarbonImmutable::parse($m['month'].'-01')->translatedFormat(count($profit['months']) > 12 ? 'M y' : 'M'), 'value' => $m['profit']], $profit['months']),
                __('Profit as money comes in, by month'), $currency, 'positive'),
        ]);
    }

    /** The same figures as a spreadsheet, for those who may export. */
    public function export(Request $request, CashForecast $forecasts, Ageing $ageings, ProfitReport $profits): Response
    {
        Gate::authorize('view-reports');
        $tenant = $this->current->get() ?? abort(403);
        abort_unless($request->user()?->roleIn($tenant->id)?->canExport() ?? false, 403);
        Entitlements::for($tenant)->assertEnabled(Feature::AdvancedReports);

        $filters = ReportFilters::fromRequest($request);
        [$bytes] = Workbook::xlsx(ReportsWorkbook::sheets($forecasts->for($filters), $ageings->for($filters), $profits->for($filters)), Locale::direction() === 'rtl');

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="qistas-reports-'.today()->format('Y-m-d').'.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, string> */
    public static function bucketLabels(): array
    {
        return [
            'current' => __('Not late'),
            'days_1_30' => __('1–30 days late'),
            'days_31_60' => __('31–60 days late'),
            'days_61_90' => __('61–90 days late'),
            'days_90_plus' => __('Over 90 days late'),
        ];
    }
}
