import 'package:flutter/foundation.dart';

import '../../core/money.dart';

/// One instalment in the glance: which contract, what is still owed on it and the day it falls due. A customer's name
/// is here only when the app asked for names (and the owner allowed them).
@immutable
class GlanceItem {
  const GlanceItem({required this.reference, required this.amount, required this.dueOn, this.name});

  factory GlanceItem.fromJson(Map<String, dynamic> json) => GlanceItem(
        reference: (json['reference'] ?? '').toString(),
        amount: Money.parse((json['amount'] ?? '0.00').toString()),
        dueOn: (json['due_on'] ?? '').toString(),
        name: json['name']?.toString(),
      );

  final String reference;
  final Money amount;

  /// YYYY-MM-DD, the workspace's own day.
  final String dueOn;
  final String? name;
}

/// Today's money in a glance, as `GET /glance` writes it: what the home-screen widget is drawn from. Amounts are read
/// exactly, from the API's two-decimal strings.
@immutable
class GlanceData {
  const GlanceData({
    required this.enabled,
    required this.currency,
    required this.dueCount,
    required this.dueAmount,
    required this.lateCount,
    required this.lateAmount,
    required this.next,
    required this.generatedAt,
  });

  /// The answer of a workspace that does not have the widget: only "off".
  factory GlanceData.disabled() => GlanceData(
        enabled: false,
        currency: '',
        dueCount: 0,
        dueAmount: Money.zero,
        lateCount: 0,
        lateAmount: Money.zero,
        next: const [],
        generatedAt: DateTime.fromMillisecondsSinceEpoch(0),
      );

  factory GlanceData.fromJson(Map<String, dynamic> json) {
    if (json['enabled'] != true) return GlanceData.disabled();

    final next = json['next'];

    return GlanceData(
      enabled: true,
      currency: (json['currency'] ?? '').toString(),
      dueCount: (json['due_count'] as num?)?.toInt() ?? 0,
      dueAmount: Money.parse((json['due_amount'] ?? '0.00').toString()),
      lateCount: (json['late_count'] as num?)?.toInt() ?? 0,
      lateAmount: Money.parse((json['late_amount'] ?? '0.00').toString()),
      next: next is List<dynamic> ? [for (final row in next.whereType<Map<String, dynamic>>()) GlanceItem.fromJson(row)] : const [],
      generatedAt: DateTime.tryParse((json['generated_at'] ?? '').toString())?.toLocal() ?? DateTime.fromMillisecondsSinceEpoch(0),
    );
  }

  final bool enabled;
  final String currency;

  /// Instalments due today, or already due and still inside their grace days.
  final int dueCount;
  final Money dueAmount;

  /// Instalments past their last day of grace.
  final int lateCount;
  final Money lateAmount;

  /// The first three: those due today, then those coming this week.
  final List<GlanceItem> next;

  /// When the server made the snapshot.
  final DateTime generatedAt;
}
