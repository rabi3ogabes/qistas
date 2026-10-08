import 'package:flutter/foundation.dart';

/// An exact amount of money, held as a whole number of cents.
///
/// Money is never a `double`: 0.1 + 0.2 is not 0.3 as a double, and a ledger must add up to the cent. The API
/// sends amounts as two-decimal strings ("1200.00") and this class reads and writes exactly those.
@immutable
class Money implements Comparable<Money> {
  const Money._(this.cents);

  /// A whole number of cents.
  factory Money.fromCents(BigInt cents) => Money._(cents);

  /// Reads an amount the way the API writes it: optional minus, digits, at most two decimals.
  ///
  /// Throws a [FormatException] for anything else (no thousands separators, no exponents, no third decimal).
  factory Money.parse(String text) =>
      tryParse(text) ?? (throw FormatException('Not an amount with at most two decimals', text));

  static final RegExp _plain = RegExp(r'^(-?)(\d+)(?:\.(\d{1,2}))?$');

  /// Like [parse], or null when the text is not an amount.
  static Money? tryParse(String text) {
    final match = _plain.firstMatch(text);
    if (match == null) return null;

    final whole = BigInt.parse(match.group(2)!);
    final fraction = (match.group(3) ?? '').padRight(2, '0');
    final cents = whole * BigInt.from(100) + BigInt.parse(fraction.isEmpty ? '0' : fraction);

    return Money._(match.group(1) == '-' ? -cents : cents);
  }

  /// Reads an amount a person typed: Western, Arabic-Indic or Persian digits, a decimal point or comma or the
  /// Arabic decimal separator, and thousands separators. Null when it is not an amount.
  static Money? parseTyped(String typed) {
    var text = _westernDigits(typed).replaceAll('٫', '.').replaceAll(RegExp('[\\s  ٬]'), '');
    if (text.isEmpty) return null;

    if (text.contains(',')) {
      if (text.contains('.')) {
        text = text.replaceAll(',', '');
      } else if (RegExp(r'^\d{1,3}(,\d{3})+$').hasMatch(text)) {
        text = text.replaceAll(',', ''); // 1,200 is twelve hundred
      } else if (RegExp(r'^\d+,\d{1,2}$').hasMatch(text)) {
        text = text.replaceAll(',', '.'); // 12,50 is twelve and a half
      } else {
        return null;
      }
    }

    return tryParse(text);
  }

  static const Map<String, String> _digitMap = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', //
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };

  static String _westernDigits(String text) => text.split('').map((c) => _digitMap[c] ?? c).join();

  static final Money zero = Money._(BigInt.zero);

  /// Cents, positive or negative.
  final BigInt cents;

  Money operator +(Money other) => Money._(cents + other.cents);

  Money operator -(Money other) => Money._(cents - other.cents);

  Money operator -() => Money._(-cents);

  bool operator >(Money other) => cents > other.cents;

  bool operator >=(Money other) => cents >= other.cents;

  bool operator <(Money other) => cents < other.cents;

  bool operator <=(Money other) => cents <= other.cents;

  bool get isZero => cents == BigInt.zero;

  bool get isPositive => cents > BigInt.zero;

  bool get isNegative => cents < BigInt.zero;

  @override
  int compareTo(Money other) => cents.compareTo(other.cents);

  @override
  bool operator ==(Object other) => other is Money && other.cents == cents;

  @override
  int get hashCode => cents.hashCode;

  /// "1200.00": exactly what the API accepts and returns.
  String toDecimalString() {
    final sign = isNegative ? '-' : '';
    final digits = cents.abs().toString().padLeft(3, '0');

    return '$sign${digits.substring(0, digits.length - 2)}.${digits.substring(digits.length - 2)}';
  }

  /// For people: "SAR 1,200.00", always with Western digits so an amount reads the same in every language.
  /// A negative amount puts its minus first: "-SAR 150.00". Pass null to leave the currency out.
  String format(String? currency) {
    final digits = cents.abs().toString().padLeft(3, '0');
    final whole = digits.substring(0, digits.length - 2).replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
    final amount = '$whole.${digits.substring(digits.length - 2)}';

    return '${isNegative ? '-' : ''}${currency == null ? '' : '$currency '}$amount';
  }

  @override
  String toString() => toDecimalString();
}
