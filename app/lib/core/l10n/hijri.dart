import 'package:flutter/foundation.dart';

import 'hijri_table.dart';

/// How the workspace shows dates (Win Plan CT26): `off` (Gregorian only), `beside` (the Hijri day after the Gregorian
/// one) or `first`. Set from the signed-in account (see QistasApp), read by the date formatters and the day picker.
/// What is sent to the server is always the Gregorian day.
abstract final class HijriDisplay {
  static String mode = 'off';

  static bool get on => mode == 'beside' || mode == 'first';
}

/// The month names and wording per language: exactly what the website says (both are checked against
/// shared/hijri-vectors.json).
const Map<String, List<String>> hijriMonthNames = {
  'en': ['Muharram', 'Safar', 'Rabi al-Awwal', 'Rabi al-Akhir', 'Jumada al-Ula', 'Jumada al-Akhirah', 'Rajab', 'Shaban', 'Ramadan', 'Shawwal', 'Dhu al-Qadah', 'Dhu al-Hijjah'],
  'ar': ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'],
  'fr': ['Mouharram', 'Safar', 'Rabia al-Awal', 'Rabia al-Thani', 'Joumada al-Oula', 'Joumada al-Thania', 'Rajab', 'Chaabane', 'Ramadan', 'Chawwal', 'Dhou al-Qi’da', 'Dhou al-Hijja'],
  'es': ['Muharram', 'Safar', 'Rabi al-Awal', 'Rabi al-Thani', 'Yumada al-Ula', 'Yumada al-Thani', 'Rayab', 'Shaabán', 'Ramadán', 'Shawwal', 'Dhu al-Qada', 'Dhu al-Hiyya'],
  'ur': ['محرم', 'صفر', 'ربیع الاول', 'ربیع الثانی', 'جمادی الاول', 'جمادی الثانی', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذوالقعدہ', 'ذوالحجہ'],
};

const Map<String, String> hijriPatterns = {
  'en': ':day :month :year AH',
  'ar': ':day :month :year هـ',
  'fr': ':day :month :year H',
  'es': ':day :month :year H',
  'ur': ':day :month :year ھ',
};

/// A day of the Umm al-Qura calendar, from the table generated from ICU (the one the website reads), so the app and
/// the website agree to the day. Covers [hijriFirstYear] to [hijriLastYear] (1937 to 2077).
@immutable
class HijriDate {
  const HijriDate(this.year, this.month, this.day);

  final int year;
  final int month;
  final int day;

  static final DateTime _epoch = DateTime.utc(int.parse(hijriEpoch.substring(0, 4)), int.parse(hijriEpoch.substring(5, 7)), int.parse(hijriEpoch.substring(8, 10)));

  /// Days from the epoch to the start of each month, then to the end of the table.
  static final List<int> _starts = () {
    final starts = <int>[0];
    for (final year in hijriYears) {
      for (final bit in year.split('')) {
        starts.add(starts.last + (bit == '1' ? 30 : 29));
      }
    }

    return starts;
  }();

  /// The Hijri day of a Gregorian one (its calendar day; the time is ignored), or null outside the table.
  static HijriDate? fromGregorian(DateTime date) {
    final days = DateTime.utc(date.year, date.month, date.day).difference(_epoch).inDays;
    if (days < 0 || days >= _starts.last) return null;

    var low = 0;
    var high = _starts.length - 2;
    while (low < high) {
      final mid = (low + high + 1) ~/ 2;
      if (_starts[mid] <= days) {
        low = mid;
      } else {
        high = mid - 1;
      }
    }

    return HijriDate(hijriFirstYear + low ~/ 12, low % 12 + 1, days - _starts[low] + 1);
  }

  /// 29 or 30; null outside the table.
  static int? monthLength(int year, int month) {
    if (year < hijriFirstYear || year > hijriLastYear || month < 1 || month > 12) return null;

    return hijriYears[year - hijriFirstYear][month - 1] == '1' ? 30 : 29;
  }

  /// The Gregorian day (a local date, midnight) of a Hijri one; null for a day the month does not have.
  static DateTime? gregorian(int year, int month, int day) {
    final length = monthLength(year, month);
    if (length == null || day < 1 || day > length) return null;
    final utc = _epoch.add(Duration(days: _starts[(year - hijriFirstYear) * 12 + month - 1] + day - 1));

    return DateTime(utc.year, utc.month, utc.day);
  }

  /// A Hijri date as people type it: 1447-08-13 or 13/08/1447, with - / . or spaces, in any digits.
  static DateTime? parse(String typed) {
    final text = _ascii(typed).trim();
    var match = RegExp(r'^(\d{4})[-/. ](\d{1,2})[-/. ](\d{1,2})$').firstMatch(text);
    if (match != null) return gregorian(int.parse(match[1]!), int.parse(match[2]!), int.parse(match[3]!));
    match = RegExp(r'^(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{4})$').firstMatch(text);
    if (match != null) return gregorian(int.parse(match[3]!), int.parse(match[2]!), int.parse(match[1]!));

    return null;
  }

  /// This day's Gregorian day.
  DateTime? toGregorian() => gregorian(year, month, day);

  /// The month's name in [language] (English when the language has none).
  static String monthName(int month, String language) => (hijriMonthNames[language] ?? hijriMonthNames['en']!)[month - 1];

  /// "13 Shaban 1447 AH" in [language].
  String format(String language) => (hijriPatterns[language] ?? hijriPatterns['en']!)
      .replaceFirst(':day', '$day')
      .replaceFirst(':month', monthName(month, language))
      .replaceFirst(':year', '$year');

  static String _ascii(String text) => text.replaceAllMapped(RegExp('[٠-٩۰-۹٫]'), (m) {
        final code = m[0]!.codeUnitAt(0);
        if (code == 0x066B) return '.';

        return '${code >= 0x06F0 ? code - 0x06F0 : code - 0x0660}';
      });

  @override
  bool operator ==(Object other) => other is HijriDate && other.year == year && other.month == month && other.day == day;

  @override
  int get hashCode => Object.hash(year, month, day);

  @override
  String toString() => '$year-$month-$day';
}

/// [gregorian] with the workspace's Hijri day beside it or first: "1 Feb 2026 (13 Shaban 1447 AH)". Unchanged while
/// Hijri dates are off, or for a day outside the table.
String withHijri(String gregorian, DateTime day, String language) {
  if (!HijriDisplay.on) return gregorian;
  final hijri = HijriDate.fromGregorian(day)?.format(language);
  if (hijri == null) return gregorian;

  return HijriDisplay.mode == 'first' ? '$hijri ($gregorian)' : '$gregorian ($hijri)';
}
