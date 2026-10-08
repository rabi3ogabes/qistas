import 'package:intl/intl.dart';

import '../money.dart';

const String _arabicIndic = '٠١٢٣٤٥٦٧٨٩';
const String _persian = '۰۱۲۳۴۵۶۷۸۹';

/// Digits are Western in every language, as on the website: an amount, a date or a phone number must look the same
/// wherever it is read.
String westernDigits(String text) => text.split('').map((c) {
      final a = _arabicIndic.indexOf(c);
      if (a >= 0) return '$a';
      final p = _persian.indexOf(c);

      return p >= 0 ? '$p' : c;
    }).join();

/// "7 Oct 2026" in the reader's language, from a YYYY-MM-DD date.
String formatDay(String iso, String language) {
  final date = DateTime.tryParse(iso);
  if (date == null) return iso;

  return westernDigits(DateFormat.yMMMd(language).format(date));
}

/// A moment in the reader's own time zone: "7 Oct 2026".
String formatMoment(DateTime moment, String language) => westernDigits(DateFormat.yMMMd(language).format(moment.toLocal()));

/// "7 Oct, 14:05".
String formatMomentLong(DateTime moment, String language) {
  final local = moment.toLocal();

  return westernDigits('${DateFormat.MMMd(language).format(local)}, ${DateFormat.Hm(language).format(local)}');
}

/// An amount with its currency: "SAR 1,200.00".
String formatMoney(Money amount, String currency) => amount.format(currency);

/// A plain date for the server: 2026-10-07.
String isoDay(DateTime day) =>
    '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';
