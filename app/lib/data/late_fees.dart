import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// How a contract charges for lateness (Win Plan K4), as it stood when the contract was made: a later change to the
/// workspace's policy never alters it.
@immutable
class LateFeePolicy {
  const LateFeePolicy({required this.type, required this.amount, required this.frequency, this.capPerInstallment, this.capPerContract, this.charity = false});

  static LateFeePolicy? fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;
    Money? cap(Object? value) => value == null ? null : Money.parse(value.toString());

    return LateFeePolicy(
      type: (json['type'] ?? 'fixed').toString(),
      amount: Money.parse((json['amount'] ?? '0').toString()),
      frequency: (json['frequency'] ?? 'once').toString(),
      capPerInstallment: cap(json['cap_installment']),
      capPerContract: cap(json['cap_contract']),
      charity: json['charity'] == true,
    );
  }

  /// fixed (an amount) or percent (of what is still owed on the instalment when the fee is charged).
  final String type;

  /// Money for a fixed fee, the percent otherwise ("2.50").
  final Money amount;

  /// once, daily, weekly or monthly, from the day after the grace days.
  final String frequency;
  final Money? capPerInstallment;
  final Money? capPerContract;

  /// The fees go to charity and are kept apart from the business's income.
  final bool charity;

  bool get isPercent => type == 'percent';
}

/// One fee for one period an instalment was late: its own line, paid by payments or waived with a reason.
@immutable
class LateFee {
  const LateFee({
    required this.id,
    required this.installmentNumber,
    required this.period,
    required this.chargedOn,
    required this.amount,
    required this.paidAmount,
    required this.remaining,
    required this.status,
    this.charity = false,
    this.waivedReason,
    this.waivedAt,
    this.waivedBy,
  });

  factory LateFee.fromJson(Map<String, dynamic> json) {
    final by = json['waived_by'];

    return LateFee(
      id: json['id'].toString(),
      installmentNumber: (json['installment_number'] as num?)?.toInt() ?? 0,
      period: (json['period'] as num?)?.toInt() ?? 1,
      chargedOn: (json['charged_on'] ?? '').toString(),
      amount: Money.parse((json['amount'] ?? '0').toString()),
      paidAmount: Money.parse((json['paid_amount'] ?? '0').toString()),
      remaining: Money.parse((json['remaining'] ?? '0').toString()),
      status: (json['status'] ?? 'open').toString(),
      charity: json['charity'] == true,
      waivedReason: json['waived_reason']?.toString(),
      waivedAt: json['waived_at']?.toString(),
      waivedBy: by is Map<String, dynamic> ? by['name']?.toString() : null,
    );
  }

  final String id;
  final int installmentNumber;
  final int period;

  /// The first day of the period it is for (YYYY-MM-DD).
  final String chargedOn;
  final Money amount;
  final Money paidAmount;
  final Money remaining;

  /// open, paid or waived.
  final String status;
  final bool charity;
  final String? waivedReason;
  final String? waivedAt;

  /// Who waived it, by name.
  final String? waivedBy;

  bool get isOpen => status == 'open';
}
