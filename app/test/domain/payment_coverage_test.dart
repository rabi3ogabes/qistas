import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/domain/payment_coverage.dart';

/// What a payment would cover, worked out on the phone by the server's rule (App\Domain\Ledger\PaymentAllocator):
/// the oldest due first, ties by number, each filled before the next is touched.
void main() {
  Installment installment(int number, String due, String remaining, {String amount = '100.00'}) => Installment(
        id: 'i$number', number: number, dueDate: due, amount: Money.parse(amount), paidAmount: Money.parse(amount) - Money.parse(remaining),
        remaining: Money.parse(remaining), status: remaining == '0.00' ? 'paid' : 'pending', state: 'upcoming',
      );

  final schedule = [
    installment(3, '2026-03-01', '100.00'),
    installment(1, '2026-01-01', '0.00'),
    installment(2, '2026-02-01', '40.00'),
  ];

  test('pays the oldest unpaid instalment first and tells what is left on the last one', () {
    final coverage = paymentCoverage(schedule, Money.parse('100.00'))!;

    expect(coverage.covers.map((c) => (c.number, c.amount.toDecimalString(), c.settles)).toList(), [(2, '40.00', true), (3, '60.00', false)]);
    expect(coverage.leftOnLast?.toDecimalString(), '40.00');
    expect(coverage.owedAfter.toDecimalString(), '40.00');
  });

  test('says nothing for more than is owed, which the server refuses', () {
    expect(paymentCoverage(schedule, Money.parse('140.01')), isNull);
  });

  test('settles everything with exactly what is owed', () {
    final coverage = paymentCoverage(schedule, Money.parse('140.00'))!;

    expect(coverage.covers.every((c) => c.settles), isTrue);
    expect(coverage.leftOnLast?.toDecimalString(), '0.00');
    expect(coverage.owedAfter.isZero, isTrue);
  });
}
