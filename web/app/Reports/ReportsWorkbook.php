<?php

namespace App\Reports;

use App\Exports\Sheet;
use App\Support\Money;

/**
 * The reports as a spreadsheet (Win Plan K8, decision D7): the very figures the page shows, one sheet a report, the
 * amounts as numbers so Excel can add them up.
 */
final class ReportsWorkbook
{
    /**
     * @param  array<string, mixed>  $forecast  CashForecast::for()
     * @param  array<string, mixed>  $ageing  Ageing::for()
     * @param  array<string, mixed>  $profit  ProfitReport::for()
     * @return list<Sheet>
     */
    public static function sheets(array $forecast, array $ageing, array $profit): array
    {
        $number = fn (string $amount): float => (float) Money::round($amount, 2);

        $forecastRows = array_map(fn (array $week) => [$week['from'], $week['to'], $number($week['amount'])], $forecast['weeks']);
        $forecastRows[] = [__('Expected in the next 30 days'), null, $number($forecast['windows']['days_30'])];
        $forecastRows[] = [__('Expected in the next 60 days'), null, $number($forecast['windows']['days_60'])];
        $forecastRows[] = [__('Expected in the next 90 days'), null, $number($forecast['windows']['days_90'])];
        $forecastRows[] = [__('Already due, not paid'), null, $number($forecast['already_due'])];
        $forecastRows[] = [__('Late fees owed'), null, $number($forecast['late_fees'])];

        $ageingRows = array_map(fn (array $row) => [
            $row['reference'], $row['customer']['name'], ...array_map(fn (string $bucket) => $number($row[$bucket]), Ageing::BUCKETS), $number($row['total']),
        ], $ageing['contracts']);
        $ageingRows[] = [__('Total'), null, ...array_map(fn (string $bucket) => $number($ageing['buckets'][$bucket]), Ageing::BUCKETS), $number($ageing['total'])];

        $months = array_map(fn (array $m) => [$m['month'], $number($m['profit'])], $profit['months']);
        $months[] = [__('Total'), $number($profit['total'])];

        return [
            new Sheet('forecast', __('Forecast'), [__('From'), __('To'), __('Expected')], $forecastRows),
            new Sheet('ageing', __('Ageing'), [__('Contract'), __('Customer'), __('Not late'), __('1–30 days late'), __('31–60 days late'), __('61–90 days late'), __('Over 90 days late'), __('Total')], $ageingRows),
            new Sheet('profit_months', __('Profit by month'), [__('Month'), __('Profit')], $months),
            new Sheet('profit_investors', __('Profit by investor'), [__('Investor'), __('Profit')], array_map(fn (array $i) => [$i['name'], $number($i['profit'])], $profit['investors'])),
            new Sheet('profit_products', __('Profit by product'), [__('Product'), __('Profit')], array_map(fn (array $p) => [$p['name'], $number($p['profit'])], $profit['products'])),
        ];
    }
}
