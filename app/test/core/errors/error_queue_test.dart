import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/errors/error_queue.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The reports a phone could not send yet: at most 20, none older than 6 hours, kept on the phone between launches.
void main() {
  late SharedPreferences prefs;
  late DateTime now;

  ErrorQueue queue() => ErrorQueue(prefs, clock: () => now);

  Map<String, dynamic> report(int number) => {'class': 'Error$number', 'message': 'm$number'};

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    now = DateTime(2026, 11, 5, 9);
  });

  test('hands back what was added, oldest first, and then is empty', () async {
    await queue().add(report(1));
    now = now.add(const Duration(minutes: 1));
    await queue().add(report(2));

    final drained = await queue().drain();

    expect([for (final entry in drained) entry.report['class']], ['Error1', 'Error2']);
    expect(await queue().drain(), isEmpty);
    expect(prefs.getString(ErrorQueue.key), isNull, reason: 'nothing is left on the phone');
  });

  test('keeps at most 20 reports: the 21st pushes out the oldest', () async {
    for (var i = 1; i <= 21; i++) {
      await queue().add(report(i));
    }

    final drained = await queue().drain();

    expect(drained, hasLength(20));
    expect(drained.first.report['class'], 'Error2');
    expect(drained.last.report['class'], 'Error21');
  });

  test('drops what is more than 6 hours old when it is read, and keeps what is exactly 6 hours old', () async {
    await queue().add(report(1));
    now = now.add(const Duration(hours: 5, minutes: 59));
    await queue().add(report(2));
    now = now.add(const Duration(minutes: 1));

    final drained = await queue().drain();

    expect([for (final entry in drained) entry.report['class']], ['Error1', 'Error2'], reason: 'the first is exactly 6 hours old');

    await queue().add(report(3));
    now = now.add(const Duration(hours: 6, seconds: 1));

    expect(await queue().drain(), isEmpty);
  });

  test('an old report does not take a place from a new one', () async {
    for (var i = 1; i <= 20; i++) {
      await queue().add(report(i));
    }
    now = now.add(const Duration(hours: 7));
    await queue().add(report(21));

    final drained = await queue().drain();

    expect([for (final entry in drained) entry.report['class']], ['Error21']);
  });

  test('a report from the future, as after a clock set back, does not outlive its 6 hours', () async {
    await queue().add(report(1));
    now = now.subtract(const Duration(days: 2));

    expect(await queue().drain(), isEmpty);
  });

  test('survives a new launch: it is kept in the preferences', () async {
    await queue().add(report(1));
    final again = await SharedPreferences.getInstance();

    final drained = await ErrorQueue(again, clock: () => now).drain();

    expect(drained.single.report, report(1));
  });

  test('a damaged store is an empty queue, never an error', () async {
    for (final damaged in ['not json', '{"a":1}', '[1,2,{"at":"x"}]', '[{"at":1}]', '']) {
      SharedPreferences.setMockInitialValues({ErrorQueue.key: damaged});
      prefs = await SharedPreferences.getInstance();

      expect(await queue().drain(), isEmpty, reason: damaged);
      await queue().add(report(1));
      expect(await queue().drain(), hasLength(1), reason: damaged);
    }
  });

  test('puts back what could not be sent, ahead of what came in meanwhile', () async {
    await queue().add(report(1));
    now = now.add(const Duration(minutes: 1));
    await queue().add(report(2));
    final batch = await queue().drain();

    now = now.add(const Duration(minutes: 1));
    await queue().add(report(3));
    await queue().restore(batch);

    final drained = await queue().drain();

    expect([for (final entry in drained) entry.report['class']], ['Error1', 'Error2', 'Error3']);
  });

  test('puts back no more than 20 and nothing older than 6 hours', () async {
    for (var i = 1; i <= 20; i++) {
      await queue().add(report(i));
    }
    final batch = await queue().drain();
    await queue().add(report(21));
    await queue().restore(batch);

    final drained = await queue().drain();

    expect(drained, hasLength(20));
    expect(drained.first.report['class'], 'Error2');
    expect(drained.last.report['class'], 'Error21');

    await queue().restore(batch);
    now = now.add(const Duration(hours: 7));

    expect(await queue().drain(), isEmpty);
  });

  test('clear empties it', () async {
    await queue().add(report(1));
    await queue().clear();

    expect(await queue().drain(), isEmpty);
    expect(prefs.getString(ErrorQueue.key), isNull);
  });

  test('reports added at the same moment are all kept, none lost to each other', () async {
    await Future.wait([for (var i = 1; i <= 10; i++) queue().add(report(i))]);

    expect(await queue().drain(), hasLength(10));
  });

  test('a clear and an add at the same moment leave a queue that can be read', () async {
    await queue().add(report(1));
    await Future.wait([queue().clear(), queue().add(report(2))]);

    final text = prefs.getString(ErrorQueue.key);
    final drained = await queue().drain();

    expect(drained.length, lessThanOrEqualTo(1));
    if (text != null) expect(() => jsonDecode(text), returnsNormally);
  });

  test('stores only the report and the moment it was made', () async {
    await queue().add(report(1));

    final stored = jsonDecode(prefs.getString(ErrorQueue.key)!) as List<dynamic>;

    expect((stored.single as Map<String, dynamic>).keys.toSet(), {'at', 'report'});
  });
}
