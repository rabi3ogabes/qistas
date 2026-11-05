import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/domain/schedule_generator.dart';

/// The Settings copy promises that a crash report carries "never your data or your customers'". The scrubber cannot find
/// a name in free text, so the promise rests on this: no error the app itself raises builds its message out of values
/// (`throw StateError('No customer $name')`). A message made of words only is safe; one that interpolates is listed here,
/// with the reason it cannot carry anything of a customer's.
///
/// What is looked for: `throw X(...)` and the plain error classes (`Exception(`, `StateError(`, `FormatException(`,
/// `ArgumentError(`, ...), with a `$` anywhere between their parentheses.
void main() {
  /// The errors whose message interpolates something, found in [source] as `line: text`.
  List<String> offenders(String source) {
    final found = <String>[];
    final start = RegExp(r'\b(?:throw\s+(?:const\s+)?[A-Za-z_]\w*(?:\.\w+)?\s*\(|(?:const\s+|new\s+)?(?:Exception|StateError|FormatException|ArgumentError|UnsupportedError|UnimplementedError|AssertionError|RangeError|TypeError)(?:\.\w+)?\s*\()');

    for (final match in start.allMatches(source)) {
      // The text between the parentheses, skipping what is inside quotes.
      var depth = 0;
      String? quote;
      var end = match.end - 1;
      for (; end < source.length; end++) {
        final char = source[end];
        if (quote != null) {
          if (char == r'\') {
            end++;
          } else if (char == quote) {
            quote = null;
          }
        } else if (char == "'" || char == '"') {
          quote = char;
        } else if (char == '(') {
          depth++;
        } else if (char == ')') {
          depth--;
          if (depth == 0) break;
        }
      }

      final call = source.substring(match.start, end < source.length ? end + 1 : source.length);
      if (call.contains(r'$')) {
        final line = '\n'.allMatches(source.substring(0, match.start)).length + 1;
        found.add('$line: ${call.replaceAll(RegExp(r'\s+'), ' ')}');
      }
    }

    return found;
  }

  group('the scan itself', () {
    test('finds a message that interpolates a value', () {
      expect(offenders(r"void f(String name) { throw StateError('No customer $name'); }"), hasLength(1));
      expect(offenders(r"void f(String name) { throw Exception('No customer ${name.trim()}'); }"), hasLength(1));
      expect(offenders(r"final e = FormatException('Not an amount', text); final f = ArgumentError.value(x, 'y', 'bad $x');"), hasLength(1));
      expect(offenders(r"void f() { throw MyException('a', 'b (${[1, 2].join(', ')})'); }"), hasLength(1));
    });

    test('lets a message of words through, and a call that only has parentheses around it', () {
      expect(offenders("void f() { throw StateError('This offer did not come from the store.'); }"), isEmpty);
      expect(offenders(r"void f() { throw StateError('words'); print('$name'); }"), isEmpty);
      expect(offenders(r"void f() { try {} on Exception catch (e) { print('$e'); } }"), isEmpty);
      expect(offenders('void f() { throw e; }'), isEmpty);
    });

    test('reads the line', () {
      expect(offenders("\n\nvoid f(String n) { throw StateError('x \$n'); }").single, startsWith('3: '));
    });
  });

  test('no error raised in lib/ builds its message out of values', () {
    // Each exception here was read, and cannot carry anything of a customer's. A new one has to be read the same way.
    const allowed = <(String file, String snippet, String why)>[
      ('lib/core/api/api_client.dart', r"'http_$status'", 'the HTTP status, a number; an ApiException is an answer of the server and is never reported'),
      ('lib/domain/schedule_generator.dart', r'between 1 and $maxCount', 'the limit of instalments, a constant of ours'),
      ('lib/domain/schedule_generator.dart', r"'Row $number:", 'the number of a row of the shop\'s own dates, counting from 1'),
      ('lib/domain/schedule_generator.dart', r'${[...frequencies.keys, custom].join', 'our own list of rhythms'),
      ('lib/domain/schedule_generator.dart', r'The instalments add up to ${', 'two amounts, but toString() leaves the message out (tested below) and every caller catches it and writes its own words'),
    ];

    final problems = <String>[];
    for (final file in Directory('lib').listSync(recursive: true).whereType<File>().where((file) => file.path.endsWith('.dart'))) {
      final path = file.path.replaceAll('\\', '/');
      for (final offender in offenders(file.readAsStringSync())) {
        final text = offender.substring(offender.indexOf(': ') + 2);
        if (allowed.any((known) => known.$1 == path && text.contains(known.$2))) continue;
        problems.add('$path:$offender');
      }
    }

    expect(problems, isEmpty, reason: 'an error message that interpolates a value can put a customer into a crash report:\n${problems.join('\n')}');
  });

  test('a schedule error that holds amounts does not print them: what an uncaught one would put in a crash report', () {
    final error = InvalidScheduleException(
      'custom_schedule',
      'The instalments add up to 1250.00, but the total is 1300.00.',
      reason: 'sum',
      remaining: Money.fromCents(BigInt.from(5000)),
    );

    expect(error.toString(), isNot(contains('1250')));
    expect(error.toString(), isNot(contains('1300')));
    expect(error.toString(), contains('custom_schedule'));
    expect(error.message, contains('1250.00'), reason: 'the message itself is unchanged for whoever reads it');
  });
}
