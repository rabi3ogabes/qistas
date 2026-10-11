import 'package:flutter/foundation.dart';

/// A plan worked out in the calculator (Win Plan PP15), carried into a new contract: the form opens with these numbers
/// and shows the same schedule. Amounts are two-decimal strings, as the form reads them.
@immutable
class ContractDraft {
  const ContractDraft({required this.principal, required this.downPayment, required this.markupPercent, required this.count, required this.frequency, required this.firstDue});

  final String principal;
  final String downPayment;

  /// Profit as a percent of what is financed; zero for none.
  final String markupPercent;
  final int count;

  /// weekly, biweekly or monthly.
  final String frequency;

  final DateTime firstDue;
}
