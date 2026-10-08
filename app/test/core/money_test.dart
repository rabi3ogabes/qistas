import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/money.dart';

void main() {
  group('parsing', () {
    test('reads plain decimals exactly', () {
      expect(Money.parse('1200.00').toDecimalString(), '1200.00');
      expect(Money.parse('1200').toDecimalString(), '1200.00');
      expect(Money.parse('0.5').toDecimalString(), '0.50');
      expect(Money.parse('-150.25').toDecimalString(), '-150.25');
      expect(Money.parse('99999999999999.99').toDecimalString(), '99999999999999.99');
    });

    test('refuses anything that is not an amount with at most two decimals', () {
      for (final bad in ['', ' ', 'abc', '1e2', '1,000.00', '1.005', '1.', '.5', '--1', '1 000', '12.3.4']) {
        expect(Money.tryParse(bad), isNull, reason: '"$bad" is not an amount');
        expect(() => Money.parse(bad), throwsFormatException);
      }
    });

    test('reads digits typed on an Arabic, Urdu or Persian keyboard', () {
      expect(Money.parseTyped('١٢٠٠٫٥٠')!.toDecimalString(), '1200.50');
      expect(Money.parseTyped('۱۵۰')!.toDecimalString(), '150.00');
      expect(Money.parseTyped('1,200.50')!.toDecimalString(), '1200.50');
      expect(Money.parseTyped('1٬200٫5')!.toDecimalString(), '1200.50');
      expect(Money.parseTyped('  42 ')!.toDecimalString(), '42.00');
      expect(Money.parseTyped('12,50')!.toDecimalString(), '12.50'); // a decimal comma, as typed in French or Spanish
      expect(Money.parseTyped('1,200')!.toDecimalString(), '1200.00'); // a thousands comma
      expect(Money.parseTyped('1,2,3'), isNull);
      expect(Money.parseTyped('4x2'), isNull);
      expect(Money.parseTyped(''), isNull);
    });
  });

  group('arithmetic', () {
    test('is exact where doubles are not', () {
      var total = Money.zero;
      for (var i = 0; i < 10; i++) {
        total += Money.parse('0.10');
      }
      expect(total.toDecimalString(), '1.00'); // 0.1 added ten times as a double is 0.9999999999999999
      expect((Money.parse('0.30') - Money.parse('0.10')).toDecimalString(), '0.20');
    });

    test('compares, negates and classifies', () {
      expect(Money.parse('2.00') > Money.parse('1.99'), isTrue);
      expect(Money.parse('2.00') == Money.parse('2'), isTrue);
      expect(Money.parse('2.00').hashCode, Money.parse('2').hashCode);
      expect((-Money.parse('5')).toDecimalString(), '-5.00');
      expect(Money.zero.isZero, isTrue);
      expect(Money.parse('0.01').isPositive, isTrue);
      expect(Money.parse('-0.01').isNegative, isTrue);
      expect(Money.parse('3').compareTo(Money.parse('4')), lessThan(0));
    });
  });

  group('showing', () {
    test('writes an amount with its currency and Western digits in every language', () {
      expect(Money.parse('1200').format('SAR'), 'SAR 1,200.00');
      expect(Money.parse('1234567.8').format('USD'), 'USD 1,234,567.80');
      expect(Money.parse('0.05').format('EUR'), 'EUR 0.05');
    });

    test('puts the minus sign first', () {
      expect(Money.parse('-150').format('SAR'), '-SAR 150.00');
    });

    test('can leave the currency out', () {
      expect(Money.parse('1200').format(null), '1,200.00');
    });
  });
}
