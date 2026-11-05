import 'dart:convert';
import 'dart:io';
import 'dart:ui' show SemanticsAction, Tristate;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/core/errors/error_queue.dart';
import 'package:qistas/core/errors/error_reporter.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/harness.dart';

/// Settings > Send crash reports: on until the person turns it off, kept on the phone, and turning it off also
/// empties what was waiting to be sent.
void main() {
  const english = 'When the app hits an error it sends the kind of error and a technical trace, never your data or your customers\', so we can fix it.';
  final row = find.byKey(const ValueKey('settings-crash-reports'));
  final queuedReport = jsonEncode([
    {
      'at': DateTime.now().millisecondsSinceEpoch,
      'report': {'class': 'StateError', 'message': 'Bad state: x', 'trace': '', 'version': '1.29.0', 'os': 'android', 'os_version': 'x', 'device': 'phone'},
    },
  ]);

  Future<void> showRow(WidgetTester tester) async {
    await openSettings(tester);
    await tester.scrollUntilVisible(row, 200, scrollable: find.descendant(of: find.byType(SingleChildScrollView), matching: find.byType(Scrollable)).first);
    // The whole row, not only its edge: at a large text size it is tall, and a tap lands in the middle of it.
    await tester.ensureVisible(row);
    await tester.pump();
  }

  Switch theSwitch(WidgetTester tester) => tester.widget<Switch>(find.descendant(of: row, matching: find.byType(Switch)));

  group('the switch in Settings', () {
    testWidgets('is on by default and says in one line what is sent and what never is', (tester) async {
      await pumpApp(tester, workspaceServer());
      await showRow(tester);

      expect(find.descendant(of: row, matching: find.text('Send crash reports')), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text(english)), findsOneWidget);
      expect(theSwitch(tester).value, isTrue);
    });

    testWidgets('turns off, and the choice is kept on the phone', (tester) async {
      await pumpApp(tester, workspaceServer());
      await showRow(tester);

      await tester.tap(row);
      await settle(tester);

      expect(theSwitch(tester).value, isFalse);
      expect((await preferences()).getBool(CrashReports.key), isFalse);
      expect(CrashReports.key, 'crash_reports');
    });

    testWidgets('turns on again', (tester) async {
      await pumpApp(tester, workspaceServer());
      await showRow(tester);

      await tester.tap(find.descendant(of: row, matching: find.byType(Switch)));
      await settle(tester);
      await tester.tap(find.descendant(of: row, matching: find.byType(Switch)));
      await settle(tester);

      expect(theSwitch(tester).value, isTrue);
      expect((await preferences()).getBool(CrashReports.key), isTrue);
    });

    testWidgets('reads the choice made in an earlier run', (tester) async {
      await pumpApp(tester, workspaceServer(), preferences: {CrashReports.key: false});
      await showRow(tester);

      expect(theSwitch(tester).value, isFalse);
    });

    testWidgets('turning it off empties the reports that were waiting to be sent', (tester) async {
      await pumpApp(tester, workspaceServer(), preferences: {ErrorQueue.key: queuedReport});
      await showRow(tester);
      expect((await preferences()).getString(ErrorQueue.key), isNotNull);

      await tester.tap(row);
      await settle(tester);

      expect((await preferences()).getString(ErrorQueue.key), isNull);
    });

    testWidgets('turning it on does not touch the reports that are waiting', (tester) async {
      await pumpApp(tester, workspaceServer(), preferences: {CrashReports.key: false, ErrorQueue.key: queuedReport});
      await showRow(tester);

      await tester.tap(row);
      await settle(tester);

      expect(theSwitch(tester).value, isTrue);
      expect((await preferences()).getString(ErrorQueue.key), queuedReport);
    });

    testWidgets('is a switch a screen reader can find: one control, named, with its state, that taps', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, workspaceServer());
      await showRow(tester);

      final data = tester.getSemantics(row).getSemanticsData();

      expect(data.label, contains('Send crash reports'));
      expect(data.label, contains('never your data'));
      expect(data.flagsCollection.isToggled, Tristate.isTrue);
      expect(data.hasAction(SemanticsAction.tap), isTrue);

      tester.semantics.tap(find.semantics.byLabel(RegExp('Send crash reports')));
      await settle(tester);

      expect(theSwitch(tester).value, isFalse);
      expect(tester.getSemantics(row).getSemanticsData().flagsCollection.isToggled, Tristate.isFalse);
      handle.dispose();
    });

    for (final language in ['ar', 'fr', 'es', 'ur']) {
      testWidgets('speaks $language', (tester) async {
        final words = jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;
        await pumpApp(tester, workspaceServer(), language: language);
        await showRow(tester);

        expect(words['Send crash reports'], isNotNull);
        expect(words[english], isNotNull);
        expect(find.descendant(of: row, matching: find.text(words['Send crash reports'].toString())), findsOneWidget);
        expect(find.descendant(of: row, matching: find.text(words[english].toString())), findsOneWidget);
      });
    }

    testWidgets('holds together in Arabic at 200 % text on a small phone', (tester) async {
      // The test font is wider than any real one: if it fits, real text fits.
      await pumpApp(tester, workspaceServer(), language: 'ar', size: const Size(320, 640), textScale: 2);
      await showRow(tester);

      expect(tester.takeException(), isNull);
      expect(Directionality.of(tester.element(row)), TextDirection.rtl);
      final rowBox = tester.getRect(row);
      final switchBox = tester.getRect(find.descendant(of: row, matching: find.byType(Switch)));
      expect(rowBox.left, greaterThanOrEqualTo(0));
      expect(rowBox.right, lessThanOrEqualTo(320));
      expect(switchBox.left, greaterThanOrEqualTo(rowBox.left), reason: 'the switch stays inside its row');
      expect(switchBox.right, lessThanOrEqualTo(rowBox.right));
      expect(switchBox.center.dx, lessThan(rowBox.center.dx), reason: 'mirrored: the switch is on the left, the icon on the right');

      final control = find.descendant(of: row, matching: find.byType(Switch));
      await tester.ensureVisible(control);
      await tester.pump();
      await tester.tap(control);
      await settle(tester);
      expect(theSwitch(tester).value, isFalse);
    });

    testWidgets('in Arabic at 200 % text, in the real font, the whole row is on one small screen', (tester) async {
      await pumpApp(tester, workspaceServer(), language: 'ar', size: const Size(320, 640), textScale: 2, realFonts: true);
      await showRow(tester);

      expect(tester.takeException(), isNull);
      final rowBox = tester.getRect(row);
      expect(rowBox.height, lessThan(500), reason: 'the explanation reads across the card, not down a narrow column');

      await tester.tap(row);
      await settle(tester);
      expect(theSwitch(tester).value, isFalse);
    });

    testWidgets('holds together in English at 200 % text on a small phone', (tester) async {
      await pumpApp(tester, workspaceServer(), size: const Size(320, 640), textScale: 2);
      await showRow(tester);

      expect(tester.takeException(), isNull);
    });
  });

  group('the choice as the rest of the app reads it', () {
    test('is on until it is turned off, and turning it off empties the queue', () async {
      SharedPreferences.setMockInitialValues({ErrorQueue.key: queuedReport});
      final prefs = await SharedPreferences.getInstance();
      final container = ProviderContainer(overrides: [sharedPreferencesProvider.overrideWithValue(prefs)]);
      addTearDown(container.dispose);

      expect(container.read(crashReportsProvider), isTrue);

      await container.read(crashReportsProvider.notifier).choose(false);

      expect(container.read(crashReportsProvider), isFalse);
      expect(prefs.getBool('crash_reports'), isFalse);
      expect(prefs.getString(ErrorQueue.key), isNull);

      await container.read(crashReportsProvider.notifier).choose(true);

      expect(container.read(crashReportsProvider), isTrue);
      expect(CrashReports.isOn(prefs), isTrue);
    });
  });
}
