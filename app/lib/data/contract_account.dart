import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// One contract as the customer's screen shows it, worked out by the server: every line in date order with what it
/// added, what was paid and the balance after it; each instalment past its date and still owed where it fell due;
/// and the totals under them.
@immutable
class ContractAccount {
  const ContractAccount({
    required this.contractId,
    required this.open,
    required this.takesPayments,
    required this.nextInstallmentId,
    required this.lines,
    required this.totals,
  });

  factory ContractAccount.fromJson(Map<String, dynamic> json) => ContractAccount(
        contractId: json['contract_id'].toString(),
        open: json['open'] == true,
        takesPayments: json['takes_payments'] == true,
        nextInstallmentId: json['next_installment_id']?.toString(),
        lines: [for (final line in (json['lines'] as List<dynamic>? ?? const [])) AccountLine.fromJson(line as Map<String, dynamic>)],
        totals: AccountTotals.fromJson((json['totals'] as Map<String, dynamic>?) ?? const {}),
      );

  final String contractId;

  /// A running tab with no schedule: no instalments, so no counts.
  final bool open;

  /// More money may still be taken on it.
  final bool takesPayments;
  final String? nextInstallmentId;
  final List<AccountLine> lines;
  final AccountTotals totals;
}

@immutable
class AccountLine {
  const AccountLine({
    required this.kind,
    required this.date,
    required this.added,
    required this.paid,
    required this.balance,
    required this.method,
    required this.tag,
    required this.note,
    required this.unpaid,
  });

  factory AccountLine.fromJson(Map<String, dynamic> json) {
    Money? money(String key) => json[key] == null ? null : Money.parse(json[key].toString());
    final installment = json['installment'] as Map<String, dynamic>?;

    return AccountLine(
      kind: (json['kind'] ?? '').toString(),
      date: (json['date'] ?? '').toString(),
      added: money('added'),
      paid: money('paid'),
      balance: money('balance'),
      method: json['method']?.toString(),
      tag: json['tag']?.toString(),
      note: json['note']?.toString(),
      unpaid: installment == null ? null : UnpaidInstallment.fromJson(installment),
    );
  }

  /// sale, down_payment, payment, reversal, charge, charge_reversal, early_rebate, late_fee, late_fee_waived,
  /// cancelled, or unpaid (an instalment past its date; it carries [unpaid] and no balance).
  final String kind;

  /// The day, as 'YYYY-MM-DD'.
  final String date;
  final Money? added;
  final Money? paid;
  final Money? balance;
  final String? method;
  final String? tag;
  final String? note;
  final UnpaidInstallment? unpaid;

  bool get isUnpaid => unpaid != null;
}

@immutable
class UnpaidInstallment {
  const UnpaidInstallment({required this.id, required this.number, required this.dueDate, required this.remaining});

  factory UnpaidInstallment.fromJson(Map<String, dynamic> json) => UnpaidInstallment(
        id: json['id'].toString(),
        number: (json['number'] as num?)?.toInt() ?? 0,
        dueDate: (json['due_date'] ?? '').toString(),
        remaining: Money.parse((json['remaining'] ?? '0').toString()),
      );

  final String id;
  final int number;
  final String dueDate;
  final Money remaining;
}

@immutable
class AccountTotals {
  const AccountTotals({
    required this.instalment,
    required this.paidCount,
    required this.leftCount,
    required this.paid,
    required this.left,
    required this.late,
  });

  factory AccountTotals.fromJson(Map<String, dynamic> json) {
    Money money(String key) => Money.parse((json[key] ?? '0').toString());

    return AccountTotals(
      instalment: json['instalment'] == null ? null : money('instalment'),
      paidCount: (json['paid_count'] as num?)?.toInt(),
      leftCount: (json['left_count'] as num?)?.toInt(),
      paid: money('paid'),
      left: money('left'),
      late: money('late'),
    );
  }

  /// Null for an open contract, like the counts.
  final Money? instalment;
  final int? paidCount;
  final int? leftCount;
  final Money paid;
  final Money left;
  final Money late;
}
