import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// The ageing buckets, in order: not late yet, then by days since the last day of grace.
const ageingBuckets = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

Money _money(Object? value) => Money.parse((value ?? '0').toString());

Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : const {};

List<Map<String, dynamic>> _list(Object? value) => value is List ? value.whereType<Map<String, dynamic>>().toList() : const [];

/// What the reports cover (Win Plan RP14): a period for what happened, and optionally one investor and one product.
@immutable
class ReportFilters {
  const ReportFilters({this.period = 'last_12_months', this.from, this.to, this.investorId, this.productId});

  /// this_month, last_3_months, last_12_months, this_year or custom (with [from] and [to]).
  final String period;
  final String? from;
  final String? to;
  final String? investorId;
  final String? productId;

  Map<String, String> toQuery() => {
        'period': period,
        if (period == 'custom' && from != null) 'from': from!,
        if (period == 'custom' && to != null) 'to': to!,
        'investor_id': ?investorId,
        'product_id': ?productId,
      };

  ReportFilters copyWith({String? period, String? Function()? investorId, String? Function()? productId}) => ReportFilters(
        period: period ?? this.period,
        from: from,
        to: to,
        investorId: investorId == null ? this.investorId : investorId(),
        productId: productId == null ? this.productId : productId(),
      );

  @override
  bool operator ==(Object other) =>
      other is ReportFilters && other.period == period && other.from == from && other.to == to && other.investorId == investorId && other.productId == productId;

  @override
  int get hashCode => Object.hash(period, from, to, investorId, productId);
}

/// What the schedules say is coming (Win Plan K8): expected, never promised.
@immutable
class CashForecast {
  const CashForecast({required this.alreadyDue, required this.lateFees, required this.windows, required this.weeks});

  factory CashForecast.fromJson(Map<String, dynamic> json) {
    final windows = _map(json['windows']);

    return CashForecast(
      alreadyDue: _money(json['already_due']),
      lateFees: _money(json['late_fees']),
      windows: {for (final days in [30, 60, 90]) days: _money(windows['days_$days'])},
      weeks: [for (final week in _list(json['weeks'])) (from: week['from'].toString(), to: week['to'].toString(), amount: _money(week['amount']))],
    );
  }

  final Money alreadyDue;
  final Money lateFees;

  /// Expected in the next 30, 60 and 90 days.
  final Map<int, Money> windows;
  final List<({String from, String to, Money amount})> weeks;
}

/// How late what is owed is (Win Plan RP15).
@immutable
class AgeingReport {
  const AgeingReport({required this.buckets, required this.total, required this.contracts});

  factory AgeingReport.fromJson(Map<String, dynamic> json) {
    final buckets = _map(json['buckets']);

    return AgeingReport(
      buckets: {for (final bucket in ageingBuckets) bucket: _money(buckets[bucket])},
      total: _money(json['total']),
      contracts: [
        for (final row in _list(json['contracts']))
          AgeingRow(
            id: row['id'].toString(),
            reference: (row['reference'] ?? '').toString(),
            customerName: (_map(row['customer'])['name'] ?? '').toString(),
            buckets: {for (final bucket in ageingBuckets) bucket: _money(row[bucket])},
            total: _money(row['total']),
          ),
      ],
    );
  }

  final Map<String, Money> buckets;
  final Money total;

  /// Contract by contract, the latest first.
  final List<AgeingRow> contracts;
}

@immutable
class AgeingRow {
  const AgeingRow({required this.id, required this.reference, required this.customerName, required this.buckets, required this.total});

  final String id;
  final String reference;
  final String customerName;
  final Map<String, Money> buckets;
  final Money total;

  /// What of it is late (everything but the current bucket).
  Money get late => total - (buckets['current'] ?? Money.zero);
}

/// Profit as money comes in (Win Plan K8, CT27), by the investor ledger's rule.
@immutable
class ProfitReport {
  const ProfitReport({
    required this.from,
    required this.to,
    required this.total,
    required this.collectionRate,
    required this.months,
    required this.investors,
    required this.products,
    required this.lateFeesShop,
    required this.lateFeesCharity,
  });

  factory ProfitReport.fromJson(Map<String, dynamic> json) {
    List<({String name, Money profit})> named(Object? rows) => [for (final row in _list(rows)) (name: (row['name'] ?? '').toString(), profit: _money(row['profit']))];
    final fees = _map(json['late_fees']);

    return ProfitReport(
      from: (json['from'] ?? '').toString(),
      to: (json['to'] ?? '').toString(),
      total: _money(json['total']),
      collectionRate: json['collection_rate']?.toString(),
      months: [for (final month in _list(json['months'])) (month: month['month'].toString(), profit: _money(month['profit']))],
      investors: named(json['investors']),
      products: named(json['products']),
      lateFeesShop: _money(fees['shop']),
      lateFeesCharity: _money(fees['charity']),
    );
  }

  final String from;
  final String to;
  final Money total;

  /// Of what fell due in the period, the percent paid ("75.0"); null when nothing fell due.
  final String? collectionRate;
  final List<({String month, Money profit})> months;
  final List<({String name, Money profit})> investors;
  final List<({String name, Money profit})> products;
  final Money lateFeesShop;
  final Money lateFeesCharity;
}

/// The three reports, fetched together.
@immutable
class Reports {
  const Reports({required this.forecast, required this.ageing, required this.profit});

  final CashForecast forecast;
  final AgeingReport ageing;
  final ProfitReport profit;
}
