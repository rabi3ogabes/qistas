import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/domain/payday.dart';

/// Payday learning (daily-habit wave A, H6): the date a customer's usual payday suggests. The rule is written out in
/// shared/payday-vectors.json (hand-computed from the calendar) and the web's pay-day.js is checked against the same file,
/// so the phone and the website cannot disagree about which day to offer.
void main() {
  PayPattern pattern({int day = 27, bool endOfMonth = false}) => PayPattern(
        day: day,
        spread: 1,
        months: 6,
        within: 83,
        confidence: 'high',
        endOfMonth: endOfMonth,
        label: 'Usually pays around day $day of the month',
        basis: 'Seen in 83% of recent months',
      );

  PayPattern endOfTheMonth() => pattern(day: 28, endOfMonth: true);

  group('the shared vectors', () {
    final file = File('../shared/payday-vectors.json');
    final vectors = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    final cases = (vectors['cases'] as List<dynamic>).cast<Map<String, dynamic>>();

    test('are there to be run', () {
      expect(cases.length, greaterThanOrEqualTo(59));
    });

    for (final (index, vector) in cases.indexed) {
      final typed = vector['typed'] as String?;
      final expected = vector['expected'] as String;
      final name = '${index + 1}. ${vector['edge']}: ${typed == null ? 'null' : jsonEncode(typed)} on ${jsonEncode(vector['today'])}, '
          'day ${vector['day']}${vector['endOfMonth'] == true ? ' at the end of the month' : ''} gives ${expected.isEmpty ? 'nothing' : expected}';

      test(name, () {
        final answer = paydaySuggestion(
          typed: typed,
          today: vector['today'] as String,
          day: vector['day'] as int,
          endOfMonth: vector['endOfMonth'] as bool,
        );

        // An empty expected answer is "no suggestion", which is null here.
        expect(answer, expected.isEmpty ? isNull : expected);
      });
    }
  });

  group('nextOccurrence', () {
    test('a day later in this month', () {
      expect(nextOccurrence(DateTime(2026, 10, 7), pattern()), DateTime(2026, 10, 27));
      expect(nextOccurrence(DateTime(2026, 10, 26), pattern()), DateTime(2026, 10, 27));
    });

    test('a day already passed rolls to next month', () {
      expect(nextOccurrence(DateTime(2026, 10, 28), pattern()), DateTime(2026, 11, 27));
      expect(nextOccurrence(DateTime(2026, 12, 28), pattern()), DateTime(2027, 1, 27));
    });

    test('the end of the month in a 30-day and a 31-day month', () {
      expect(nextOccurrence(DateTime(2026, 11, 3), endOfTheMonth()), DateTime(2026, 11, 30));
      expect(nextOccurrence(DateTime(2026, 10, 3), endOfTheMonth()), DateTime(2026, 10, 31));
    });

    test('February and leap years', () {
      expect(nextOccurrence(DateTime(2027, 2, 15), endOfTheMonth()), DateTime(2027, 2, 28));
      expect(nextOccurrence(DateTime(2028, 2, 15), endOfTheMonth()), DateTime(2028, 2, 29));
      expect(nextOccurrence(DateTime(2100, 2, 10), endOfTheMonth()), DateTime(2100, 2, 28));
      expect(nextOccurrence(DateTime(2000, 2, 10), endOfTheMonth()), DateTime(2000, 2, 29));
      // The 28th is a real day in every February, and the 29th of a leap year has already passed it.
      expect(nextOccurrence(DateTime(2026, 2, 28), pattern(day: 28)), DateTime(2026, 2, 28));
      expect(nextOccurrence(DateTime(2028, 2, 29), pattern(day: 28)), DateTime(2028, 3, 28));
    });

    test('end of month means the last day of the month', () {
      for (final (from, last) in [
        (DateTime(2026, 1, 1), DateTime(2026, 1, 31)),
        (DateTime(2026, 4, 12), DateTime(2026, 4, 30)),
        (DateTime(2026, 6, 30), DateTime(2026, 6, 30)),
        (DateTime(2026, 12, 15), DateTime(2026, 12, 31)),
      ]) {
        expect(nextOccurrence(from, endOfTheMonth()), last, reason: 'from $from');
      }
      // The end of the month wins over the day the pattern also carries.
      expect(nextOccurrence(DateTime(2026, 11, 3), pattern(day: 15, endOfMonth: true)), DateTime(2026, 11, 30));
    });

    test('the same date is returned when it already falls on the day', () {
      expect(nextOccurrence(DateTime(2026, 11, 27), pattern()), DateTime(2026, 11, 27));
      expect(nextOccurrence(DateTime(2026, 11, 30), endOfTheMonth()), DateTime(2026, 11, 30));
    });

    test('is the day on the calendar, not a moment: a time of day and a zone change nothing', () {
      final evening = nextOccurrence(DateTime(2026, 10, 7, 23, 59, 59), pattern());

      expect(evening, DateTime(2026, 10, 27));
      expect(evening.isUtc, isFalse);
      expect((evening.year, evening.month, evening.day, evening.hour), (2026, 10, 27, 0));
      // The calendar fields are what count, so a UTC date reads the same day.
      expect(nextOccurrence(DateTime.utc(2026, 10, 7), pattern()), DateTime(2026, 10, 27));
    });

    test('falls back to the same day when there is no later one to offer', () {
      expect(nextPayday(DateTime(9999, 12, 30), pattern()), isNull);
      expect(nextOccurrence(DateTime(9999, 12, 30), pattern()), DateTime(9999, 12, 30));
    });
  });

  group('PayPattern.fromJson', () {
    Map<String, dynamic> body() => {
          'day': 27,
          'spread': 2,
          'months': 6,
          'within': 83,
          'confidence': 'high',
          'end_of_month': false,
          'label': 'Usually pays around day 27 of the month',
          'basis': 'Seen in 83% of recent months',
        };

    test('reads what the server sends, the words as sent', () {
      final read = PayPattern.fromJson(body())!;

      expect(
        (read.day, read.spread, read.months, read.within, read.confidence, read.endOfMonth, read.label, read.basis),
        (27, 2, 6, 83, 'high', false, 'Usually pays around day 27 of the month', 'Seen in 83% of recent months'),
      );
      expect(PayPattern.fromJson({...body(), 'day': 28, 'end_of_month': true, 'label': 'Usually pays at the end of the month'})!.endOfMonth, isTrue);
    });

    test('is null for nothing', () {
      expect(PayPattern.fromJson(null), isNull);
    });

    test('is null for anything malformed, and never throws', () {
      final broken = <Object?>[
        'a string',
        42,
        true,
        [body()],
        <String, dynamic>{},
        {...body()}..remove('day'),
        {...body(), 'day': '27'},
        {...body(), 'day': 27.5},
        {...body(), 'day': 0},
        {...body(), 'day': 29},
        {...body(), 'day': -1},
        {...body(), 'day': null},
        {...body()}..remove('end_of_month'),
        {...body(), 'end_of_month': 'yes'},
        {...body(), 'end_of_month': null},
        {...body()}..remove('label'),
        {...body(), 'label': 12},
        {...body(), 'label': '   '},
        {...body()}..remove('basis'),
        {...body(), 'basis': null},
        {...body(), 'spread': 'wide'},
        {...body(), 'months': null},
        {...body(), 'within': '83%'},
        {...body(), 'confidence': 3},
      ];

      for (final item in broken) {
        expect(() => PayPattern.fromJson(item), returnsNormally, reason: '$item');
        expect(PayPattern.fromJson(item), isNull, reason: '$item');
      }
    });

    test('keeps a whole number written as a decimal by a loose encoder', () {
      expect(PayPattern.fromJson({...body(), 'day': 27.0, 'within': 83.0})!.day, 27);
    });
  });
}
