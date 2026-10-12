import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' show DateFormat;

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/investors.dart';
import '../../data/models.dart';
import '../../data/reports.dart';
import '../billing/upgrade_sheet.dart';
import '../investors/investors_screen.dart';
import '../products/products_screen.dart';
import 'report_chart.dart';

/// The forecast, ageing and profit for some filters, fetched together.
final reportsProvider = FutureProvider.autoDispose.family<Reports, ReportFilters>((ref, filters) => ref.watch(apiProvider).reports(filters));

/// Reports (Win Plan K8, RP14, RP15, CT27): what the schedules say is coming, what is owed and how late, and profit as
/// money comes in, by month, investor and product. The same figures as the website's reports page. Part of Pro: a Free
/// workspace is shown the upgrade sheet.
class ReportsScreen extends ConsumerStatefulWidget {
  const ReportsScreen({super.key});

  @override
  ConsumerState<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends ConsumerState<ReportsScreen> {
  ReportFilters _filters = const ReportFilters();
  bool _offeredUpgrade = false;

  @override
  Widget build(BuildContext context) {
    final reports = ref.watch(reportsProvider(_filters));
    // A Free workspace: the upgrade sheet, once, the moment the answer says so.
    ref.listen(reportsProvider(_filters), (_, next) {
      final error = next.error;
      if (error is UpgradeRequired && !_offeredUpgrade) {
        _offeredUpgrade = true;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) showUpgradeSheet(context, error);
        });
      }
    });

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Reports'))),
      body: ContentColumn(
        child: reports.when(
          loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 6)),
          error: (error, _) => error is UpgradeRequired
              ? _Locked(reason: error)
              : QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(reportsProvider(_filters))),
          data: (data) => RefreshIndicator(
            onRefresh: () => ref.refresh(reportsProvider(_filters).future),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                _Filters(filters: _filters, onChanged: (filters) => setState(() => _filters = filters)),
                _Forecast(forecast: data.forecast),
                _Ageing(ageing: data.ageing),
                _Profit(profit: data.profit),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

String _currency(WidgetRef ref) => ref.watch(accountProvider)?.currency ?? '';

class _Filters extends ConsumerWidget {
  const _Filters({required this.filters, required this.onChanged});

  final ReportFilters filters;
  final ValueChanged<ReportFilters> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final account = ref.watch(accountProvider);
    final periods = {
      'this_month': context.t('This month'),
      'last_3_months': context.t('3 months'),
      'last_12_months': context.t('12 months'),
      'this_year': context.t('This year'),
    };
    final investors = (account?.entitlement('investors').isOn ?? false) ? ref.watch(investorsProvider).valueOrNull?.investors ?? const <Investor>[] : const <Investor>[];
    final products = ref.watch(productsProvider).valueOrNull ?? const <Product>[];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final MapEntry(key: period, value: label) in periods.entries)
              ChoiceChip(label: Text(label), selected: filters.period == period, onSelected: (_) => onChanged(filters.copyWith(period: period))),
          ],
        ),
        if (investors.length > 1 || products.isNotEmpty) ...[
          const SizedBox(height: 12),
          Row(
            children: [
              if (investors.length > 1)
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    key: const ValueKey('report-investor'),
                    initialValue: filters.investorId,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: context.t('Investor')),
                    items: [
                      DropdownMenuItem(child: Text(context.t('All investors'))),
                      for (final investor in investors) DropdownMenuItem(value: investor.id, child: Text(investor.name, overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (id) => onChanged(filters.copyWith(investorId: () => id)),
                  ),
                ),
              if (investors.length > 1 && products.isNotEmpty) const SizedBox(width: 10),
              if (products.isNotEmpty)
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    key: const ValueKey('report-product'),
                    initialValue: filters.productId,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: context.t('Product')),
                    items: [
                      DropdownMenuItem(child: Text(context.t('All products'))),
                      for (final product in products) DropdownMenuItem(value: product.id, child: Text(product.name, overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (id) => onChanged(filters.copyWith(productId: () => id)),
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, this.lead = false, this.note});

  final String label;
  final String value;
  final bool lead;
  final String? note;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: text.labelMedium?.copyWith(color: c.inkMuted)),
        const SizedBox(height: 4),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: Text(value, style: (lead ? text.headlineSmall : text.titleMedium)?.copyWith(fontWeight: FontWeight.w700)),
        ),
        if (note != null) ...[const SizedBox(height: 2), Text(note!, style: text.bodySmall?.copyWith(color: c.inkMuted))],
      ],
    );
  }
}

class _Forecast extends ConsumerWidget {
  const _Forecast({required this.forecast});

  final CashForecast forecast;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = _currency(ref);
    final language = ref.watch(localeProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Cash forecast')),
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Figure(label: context.t('Expected in the next 30 days'), value: forecast.windows[30]!.format(currency), lead: true),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _Figure(label: context.t('Next 60 days'), value: forecast.windows[60]!.format(currency))),
                  Expanded(child: _Figure(label: context.t('Next 90 days'), value: forecast.windows[90]!.format(currency))),
                ],
              ),
              const SizedBox(height: 18),
              ColumnChart(
                key: const ValueKey('forecast-chart'),
                values: [for (final week in forecast.weeks) week.amount.cents.toDouble()],
                labels: [for (final week in forecast.weeks) westernDigits(DateFormat.MMMd(language).format(DateTime.parse(week.from)))],
                color: c.accent,
                summary: context.t('Expected each week for the next 13 weeks'),
              ),
              const SizedBox(height: 10),
              Text(context.t('Expected: what falls due, after what is already paid'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              if (forecast.alreadyDue.isPositive || forecast.lateFees.isPositive) ...[
                const SizedBox(height: 4),
                Text(
                  [
                    context.t('Already due and not paid: :amount.', {'amount': forecast.alreadyDue.format(currency)}),
                    if (forecast.lateFees.isPositive) context.t('Late fees owed: :amount.', {'amount': forecast.lateFees.format(currency)}),
                  ].join(' '),
                  style: text.bodySmall?.copyWith(color: c.inkMuted),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Ageing extends ConsumerWidget {
  const _Ageing({required this.ageing});

  final AgeingReport ageing;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = _currency(ref);
    final labels = {
      'current': context.t('Not late'),
      'days_1_30': context.t('1–30 days late'),
      'days_31_60': context.t('31–60 days late'),
      'days_61_90': context.t('61–90 days late'),
      'days_90_plus': context.t('Over 90 days late'),
    };
    final tones = {'current': c.inkMuted.withValues(alpha: 0.45), 'days_1_30': c.warning, 'days_31_60': c.danger, 'days_61_90': c.danger, 'days_90_plus': c.danger};
    final top = ageing.buckets.values.fold<Money>(Money.zero, (max, amount) => amount > max ? amount : max);
    final late = ageing.contracts.where((row) => row.late.isPositive).take(8).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Ageing'), trailing: Text(ageing.total.format(currency), style: text.labelLarge)),
        QCard(
          child: Column(
            children: [
              for (final bucket in ageingBuckets)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Semantics(
                    label: '${labels[bucket]}: ${ageing.buckets[bucket]!.format(currency)}',
                    excludeSemantics: true,
                    child: Row(
                      children: [
                        SizedBox(width: 118, child: Text(labels[bucket]!, style: text.bodySmall)),
                        Expanded(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(4),
                            child: LinearProgressIndicator(
                              value: top.isPositive ? ageing.buckets[bucket]!.cents / top.cents : 0,
                              minHeight: 12,
                              color: tones[bucket],
                              backgroundColor: c.surfaceAlt,
                            ),
                          ),
                        ),
                        const SizedBox(width: 10),
                        SizedBox(width: 96, child: Text(ageing.buckets[bucket]!.format(currency), textAlign: TextAlign.end, style: text.labelMedium?.copyWith(fontWeight: FontWeight.w600))),
                      ],
                    ),
                  ),
                ),
              if (late.isNotEmpty) ...[
                const Divider(height: 24),
                for (final row in late)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(row.customerName, maxLines: 1, overflow: TextOverflow.ellipsis),
                    subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(row.reference, textAlign: TextAlign.start)),
                    trailing: Text(row.late.format(currency), style: text.labelLarge?.copyWith(color: c.danger)),
                    // A contract lives in the app's shell: go there, as the investor screen does.
                    onTap: () => context.go('/contracts/${row.id}'),
                  ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Profit extends ConsumerWidget {
  const _Profit({required this.profit});

  final ProfitReport profit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = _currency(ref);
    final language = ref.watch(localeProvider);

    Widget list(String title, List<({String name, Money profit})> rows) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: text.labelMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 4),
            if (rows.isEmpty) Text(context.t('No profit in this period yet.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
            for (final row in rows)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(children: [Expanded(child: Text(row.name, overflow: TextOverflow.ellipsis)), Text(row.profit.format(currency), style: text.labelLarge)]),
              ),
          ],
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Profit as money comes in')),
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Figure(label: context.t('Profit in the period'), value: profit.total.format(currency), lead: true, note: '${formatDay(profit.from, language)} – ${formatDay(profit.to, language)}'),
              const SizedBox(height: 12),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: _Figure(label: context.t('Collected of what fell due'), value: profit.collectionRate == null ? '—' : '${profit.collectionRate}%')),
                  Expanded(
                    child: _Figure(
                      label: context.t('Late fees collected'),
                      value: profit.lateFeesShop.format(currency),
                      note: profit.lateFeesCharity.isPositive ? context.t('And :amount for charity, kept apart', {'amount': profit.lateFeesCharity.format(currency)}) : null,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              ColumnChart(
                key: const ValueKey('profit-chart'),
                values: [for (final month in profit.months) month.profit.cents.toDouble()],
                labels: [for (final month in profit.months) DateFormat.MMM(language).format(DateTime.parse('${month.month}-01'))],
                color: c.positive,
                summary: context.t('Profit as money comes in, by month'),
              ),
              const SizedBox(height: 16),
              list(context.t('By investor'), profit.investors),
              const SizedBox(height: 14),
              list(context.t('By product'), profit.products),
            ],
          ),
        ),
      ],
    );
  }
}

/// A Free workspace: what Pro would give, and the way to it.
class _Locked extends StatelessWidget {
  const _Locked({required this.reason});

  final UpgradeRequired reason;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        const SizedBox(height: 24),
        Center(
          child: Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(color: c.tintSand, shape: BoxShape.circle),
            child: Icon(Icons.insights_outlined, color: c.accentText, size: 30),
          ),
        ),
        const SizedBox(height: 18),
        Text(context.t('Reports come with Pro'), textAlign: TextAlign.center, style: text.headlineSmall),
        const SizedBox(height: 10),
        Text(
          context.t('See what the next 90 days should bring in, who is late and by how much, and your profit month by month, by investor and by product, as money comes in. Every table downloads to Excel.'),
          textAlign: TextAlign.center,
          style: text.bodyLarge?.copyWith(color: c.inkMuted, height: 1.5),
        ),
        const SizedBox(height: 22),
        QButton(label: context.t('See Pro'), kind: QButtonKind.gold, onPressed: () => showUpgradeSheet(context, reason)),
      ],
    );
  }
}
