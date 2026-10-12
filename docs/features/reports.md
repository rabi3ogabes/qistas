# Reports, charts and profit (Win Plan K8, RP14, RP15, CT27)

One reports page on the web (`/app/reports`, **Reports** in the menu) and one screen in the app (Settings → Reports,
or **See the reports** under the dashboard's month). The same figures in both, from the same server code. Part of
Pro; everyone in the workspace sees them except collectors, because they show every investor's money.

## The three reports

**Cash forecast** (`App\Reports\CashForecast`). What still falls due on running contracts in the next 30, 60 and 90
days, each window counted from today, after what is already paid, and week by week for 13 weeks. Apart from it: what
was due before today and is still unpaid, and the open late fees. It is labelled *expected*: it is what the schedules
say, not a promise of what customers will do.

**Ageing** (`App\Reports\Ageing`). Every unpaid instalment of a running contract, in exactly one bucket by the days
since its last day of grace: not late, 1–30, 31–60, 61–90 and over 90 days late. By bucket, and contract by contract
with the most overdue first (the page lists the 15 most overdue; the export has all of them).

**Profit as money comes in** (`App\Reports\ProfitReport`). Read from the investor ledger itself, so it follows its rule
to the cent: each payment's share of the markup, an early settlement's rebate coming off profit, a void taking back
exactly what its payment gave. By month (the business's profit), by investor (what each kept after a partner's
commission to the business) and by product (a contract's profit split by what each item sold for, or by how many when
no prices were given; items not from the products list are grouped together). Beside it, the collection rate (of what
fell due in the period, the share paid so far) and the late fees collected, the business's apart from charity's.

## Filters

A period (this month, 3 months, 12 months, this year, or your own dates), an investor and a product
(`App\Reports\ReportFilters`). The investor and the product narrow every report to the contracts they fund or that
sold the product; for profit, a product keeps only its share. The period applies to what happened (profit, the
collection rate, late fees collected); the forecast and ageing are always as of today.

## Charts and export

- **Web:** charts are drawn by the server as inline SVG (`App\Reports\Charts\BarChart`): no chart library and no
  script, colours from the theme (dark mode and the admin's look apply), and every bar names its label and amount for a
  screen reader. On a phone the chart's words grow so they stay readable.
- **App:** the same columns drawn on Flutter's canvas (`features/reports/report_chart.dart`), rising into place unless
  the phone asks for less motion.
- **Excel:** **Download Excel** on the page (owners, managers and accountants) gives one sheet per report with exactly
  the figures the page shows (`App\Reports\ReportsWorkbook`).

## API

`GET /api/v1/reports/forecast`, `/reports/ageing` and `/reports/profit`, each taking `period`, `from`, `to`,
`investor_id` and `product_id`. A Free workspace gets 402 `feature_locked` (the app shows the upgrade sheet); a
collector gets 403. See `docs/api/openapi.yaml`.

**What you set up:** nothing. Reports are part of Pro (`advanced_reports`, a core feature).
