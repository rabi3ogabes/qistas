import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/domain/schedule_generator.dart';

/// The same vectors the PHP generator is checked against (shared/schedule-vectors.json, made by an independent
/// reference implementation): the app's live preview must give the very numbers the server will write.
void main() {
  final file = File('../shared/schedule-vectors.json');
  final vectors = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  final valid = (vectors['valid'] as List<dynamic>).cast<Map<String, dynamic>>();
  final invalid = (vectors['invalid'] as List<dynamic>).cast<Map<String, dynamic>>();
  final generator = ScheduleGenerator();

  test('the shared vectors are there to be run', () {
    expect(valid.length, greaterThanOrEqualTo(14));
    expect(invalid.length, greaterThanOrEqualTo(8));
  });

  group('valid vectors', () {
    for (final vector in valid) {
      test(vector['name'] as String, () {
        final input = ScheduleRequest.fromJson(vector['input'] as Map<String, dynamic>);
        final expected = vector['expected'] as Map<String, dynamic>;

        final result = generator.generate(input);

        expect(result.financed.toDecimalString(), expected['financed']);
        expect(result.markup.toDecimalString(), expected['markup']);
        expect(result.total.toDecimalString(), expected['total']);
        final rows = (expected['installments'] as List<dynamic>).cast<Map<String, dynamic>>();
        expect(result.installments.length, rows.length);
        for (var i = 0; i < rows.length; i++) {
          expect(result.installments[i].number, rows[i]['number'], reason: 'number of row $i');
          expect(result.installments[i].dueDate, rows[i]['due_date'], reason: 'due date of row $i');
          expect(result.installments[i].amount.toDecimalString(), rows[i]['amount'], reason: 'amount of row $i');
        }
      });
    }
  });

  group('invalid vectors', () {
    for (final vector in invalid) {
      test(vector['name'] as String, () {
        final input = ScheduleRequest.fromJson(vector['input'] as Map<String, dynamic>);

        expect(() => generator.generate(input), throwsA(isA<InvalidScheduleException>()));
      });
    }
  });

  group('whatever the input, the instalments add up to the total to the cent', () {
    final amounts = ['0.03', '1.00', '99.99', '100.00', '1000.01', '123456.78'];
    final counts = [1, 2, 3, 7, 12, 60, 120];
    for (final amount in amounts) {
      for (final count in counts) {
        if (Money.parse(amount).cents < BigInt.from(count)) continue;
        test('$amount over $count', () {
          final result = generator.generate(ScheduleRequest(
            principal: amount,
            downPayment: '0',
            markupType: 'percent',
            markupValue: '7.5',
            count: count,
            frequency: 'monthly',
            firstDueDate: '2026-01-31',
          ));
          final sum = result.installments.fold(Money.zero, (Money sum, row) => sum + row.amount);

          expect(sum, result.total);
          expect(result.installments.every((row) => row.amount.isPositive), isTrue);
          expect(result.installments.length, count);
        });
      }
    }
  });

  test('a monthly schedule keeps the day of the first due date, clamped in short months', () {
    final result = generator.generate(const ScheduleRequest(
      principal: '300', downPayment: '0', markupType: 'none', markupValue: '0', count: 5, frequency: 'monthly', firstDueDate: '2026-01-31',
    ));

    expect(result.installments.map((r) => r.dueDate).toList(), ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);
  });

  test('errors say what is wrong', () {
    expect(
      () => generator.generate(const ScheduleRequest(principal: '100', downPayment: '100', markupType: 'none', markupValue: '0', count: 3, frequency: 'monthly', firstDueDate: '2026-10-07')),
      throwsA(isA<InvalidScheduleException>().having((e) => e.field, 'field', 'down_payment')),
    );
    expect(
      () => generator.generate(const ScheduleRequest(principal: '', downPayment: '0', markupType: 'none', markupValue: '0', count: 3, frequency: 'monthly', firstDueDate: '2026-10-07')),
      throwsA(isA<InvalidScheduleException>().having((e) => e.field, 'field', 'principal')),
    );
  });
}
