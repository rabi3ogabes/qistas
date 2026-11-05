import 'dart:isolate';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/errors/trace_scrubber.dart';

/// A crash report with one Arabic letter, or an emoji, in it must cost about what one in Latin letters costs. (Its own
/// file, so that the first text outside Latin-1 that this process meets is the one measured: that is the one that pays
/// for compiling patterns for a wider alphabet.) Times are compared with those of plain text measured in the same
/// process, so that a busy machine slows both; before the fix a trace of 21 KB with one Arabic letter took 10 to 30
/// times what the same trace in Latin letters took, and several seconds.
void main() {
  // About 21 KB: a trace of a real size.
  final latin = '#0      CustomerScreen.build (package:qistas/features/customers/customer_screen.dart:42:7)\n' * 240;

  Duration timeOf(String text) {
    final stopwatch = Stopwatch()..start();
    TraceScrubber.clean(text);

    return stopwatch.elapsed;
  }

  /// The best of several runs: a machine busy with other work stalls one run, not all of them.
  Duration bestOf(String text, [int times = 5]) => [for (var i = 0; i < times; i++) timeOf(text)].reduce((a, b) => a < b ? a : b);

  test('the first text outside Latin-1 is cleaned in about the time the first text in Latin letters was', () async {
    expect(latin.length, greaterThan(20000));
    final arabicText = '$latin\nع';

    // A fresh isolate has compiled no pattern yet: each is a first time. The best of three.
    final runs = <({int latin, int arabic})>[];
    for (var i = 0; i < 3; i++) {
      runs.add(await Isolate.run(() {
        final first = Stopwatch()..start();
        TraceScrubber.clean(latin);
        final latinMs = first.elapsedMilliseconds;
        final second = Stopwatch()..start();
        TraceScrubber.clean(arabicText);

        return (latin: latinMs, arabic: second.elapsedMilliseconds);
      }));
    }
    final latinCold = runs.map((run) => run.latin).reduce((a, b) => a < b ? a : b);
    final arabicCold = runs.map((run) => run.arabic).reduce((a, b) => a < b ? a : b);

    expect(arabicCold, lessThan(latinCold * 3 + 400), reason: 'Latin $latinCold ms, then Arabic $arabicCold ms (before the fix: several seconds)');
    expect(arabicCold, lessThan(2500));
  });

  test('and every time after', () {
    final latinWarm = bestOf(latin);

    for (final text in ['$latin\nع', '$latin\n😀', '$latin\nليلى', '$latin\n日本語']) {
      final took = bestOf(text);

      expect(took.inMilliseconds, lessThan(latinWarm.inMilliseconds * 3 + 150), reason: '$took for a text ending ${text.substring(text.length - 3)}; Latin $latinWarm');
      expect(took, lessThan(const Duration(seconds: 1)));
    }
  });

  test('what is masked is still masked next to other scripts', () {
    expect(TraceScrubber.clean('تعذر الإرسال إلى layla@example.com الآن'), 'تعذر الإرسال إلى [email] الآن');
    expect(TraceScrubber.clean('اتصل على +974 5555 1234 الآن'), 'اتصل على [phone] الآن');
    expect(TraceScrubber.clean('المفتاح deadbeefdeadbeefdeadbeefdeadbeef انتهى'), 'المفتاح [token] انتهى');
    expect(TraceScrubber.clean('😀 token=abc 😀'), '😀 token=[secret] 😀');
    expect(TraceScrubber.clean('ليلى＠example.com'), '[email]');
    expect(TraceScrubber.clean('ليلى%40example.com'), '[email]');
  });

  test('a text without an @ never meets the address pattern, and one with an @ still does', () {
    expect(TraceScrubber.clean('no address here, just words and a dot.'), 'no address here, just words and a dot.');
    expect(TraceScrubber.clean('write to a@b.co now'), 'write to [email] now');
  });

  test('the controls and invisible characters that were removed by a pattern are still removed', () {
    expect(TraceScrubber.clean('a\u0000b\u0007c\u007Fd\u0085e'), 'abcde');
    expect(TraceScrubber.clean('a​b‏c⁠d﻿e­f'), 'abcdef');
    expect(TraceScrubber.clean('one\r\ntwo\rthree four'), 'one\ntwo\nthree\nfour');
    expect(TraceScrubber.clean('a b　c d'), 'a b c d');
    expect(TraceScrubber.clean('x\u{E0041}y\u{F0000}z'), 'xyz', reason: 'tag characters and private-use planes hide text');
    expect(TraceScrubber.clean('٠١٢٣٤٥٦٧٨٩ ۰۱۲۳۴۵۶۷۸۹ ０１２３４５６７８９'), '[phone] [phone] [phone]', reason: 'ten digits starting with a zero read as a phone number, in any script');
  });
}
