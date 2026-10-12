import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/core/l10n/hijri.dart';
import 'package:qistas/core/l10n/hijri_table.dart';

/// The Umm al-Qura calendar on the phone (Win Plan CT26): the same table, the same days and the same wording as the
/// website, all checked against shared/hijri-vectors.json (generated from ICU by shared/tools/generate_hijri_vectors.php).
void main() {
  final vectors = jsonDecode(File('../shared/hijri-vectors.json').readAsStringSync()) as Map<String, dynamic>;
  final samples = (vectors['samples'] as List<dynamic>).cast<Map<String, dynamic>>();

  setUpAll(() async {
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });
  tearDown(() => HijriDisplay.mode = 'off');

  test('holds the generated month lengths', () {
    expect(hijriFirstYear, vectors['first_year']);
    expect(hijriLastYear, vectors['last_year']);
    expect(hijriEpoch, vectors['epoch']);
    expect(hijriYears, (vectors['months'] as Map<String, dynamic>).values.toList());
  });

  test('converts each sample both ways and words it as the website does', () {
    for (final sample in samples) {
      final gregorian = DateTime.parse(sample['gregorian'] as String);
      final [year, month, day] = (sample['hijri'] as List<dynamic>).cast<int>();
      final hijri = HijriDate.fromGregorian(gregorian);

      expect(hijri, HijriDate(year, month, day), reason: sample['gregorian'] as String);
      expect(HijriDate.gregorian(year, month, day), gregorian, reason: sample['gregorian'] as String);
      for (final MapEntry(key: language, value: words) in (sample['words'] as Map<String, dynamic>).entries) {
        expect(hijri!.format(language), words, reason: '${sample['gregorian']} in $language');
      }
    }
  });

  test('walks every day of the table without a gap or a repeat', () {
    var day = DateTime.parse(hijriEpoch);
    HijriDate? previous;
    HijriDate? hijri;
    while ((hijri = HijriDate.fromGregorian(day)) != null) {
      if (previous != null) {
        final next = previous.day < HijriDate.monthLength(previous.year, previous.month)!
            ? HijriDate(previous.year, previous.month, previous.day + 1)
            : (previous.month < 12 ? HijriDate(previous.year, previous.month + 1, 1) : HijriDate(previous.year + 1, 1, 1));
        expect(hijri, next, reason: '$day');
      }
      previous = hijri;
      day = DateTime(day.year, day.month, day.day + 1);
    }

    expect(previous, HijriDate(hijriLastYear, 12, HijriDate.monthLength(hijriLastYear, 12)!));
    expect(day, DateTime(2077, 11, 17));
  });

  test('knows nothing outside the table, nor a day a month does not have', () {
    expect(HijriDate.fromGregorian(DateTime(1937, 3, 13)), isNull);
    expect(HijriDate.fromGregorian(DateTime(2077, 11, 17)), isNull);
    final short = hijriYears[1447 - hijriFirstYear].indexOf('0') + 1;
    expect(HijriDate.gregorian(1447, short, 29), isNotNull);
    expect(HijriDate.gregorian(1447, short, 30), isNull);
    expect(HijriDate.gregorian(1447, 13, 1), isNull);
  });

  test('reads a typed Hijri date, year first or day first, in any digits', () {
    for (final typed in ['1447-08-13', '1447/8/13', '13/08/1447', '13-8-1447', ' 13.08.1447 ', '١٣/٠٨/١٤٤٧', '۱۴۴۷-۰۸-۱۳']) {
      expect(HijriDate.parse(typed), DateTime(2026, 2, 1), reason: typed);
    }
    for (final typed in ['', 'soon', '1447-13-01', '1447-08-32', '13/08/47', '2026-02-01', '1447-08']) {
      expect(HijriDate.parse(typed), isNull, reason: typed);
    }
  });

  test('a day is shown as the workspace asks', () {
    final plain = formatDay('2026-02-01', 'en');
    final outside = formatDay('2090-01-01', 'en');
    expect(plain, isNot(contains('AH')));

    HijriDisplay.mode = 'beside';
    expect(formatDay('2026-02-01', 'en'), '$plain (13 Shaban 1447 AH)');
    expect(formatDay('2026-02-01', 'ar'), contains('13 شعبان 1447 هـ'));
    expect(formatMoment(DateTime(2026, 2, 1, 15), 'en'), '$plain (13 Shaban 1447 AH)');

    HijriDisplay.mode = 'first';
    expect(formatDay('2026-02-01', 'en'), '13 Shaban 1447 AH ($plain)');
    expect(formatDay('2090-01-01', 'en'), outside, reason: 'outside the table: the Gregorian day alone');
  });
}
