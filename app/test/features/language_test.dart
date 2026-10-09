import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/language_button.dart';
import 'package:qistas/features/auth/auth_shell.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/harness.dart';

/// The language control is a button of its own: a pill on every screen that opens a list of the five languages, each
/// in its own script. It is not a row in a menu, and Settings keeps its plain list as well.

Finder get pill => find.byType(LanguageButton);

Future<String?> savedLanguage() async => (await SharedPreferences.getInstance()).getString('language');

void main() {
  group('the button', () {
    testWidgets('is on the dashboard and shows the current language', (tester) async {
      await pumpApp(tester, workspaceServer());

      expect(pill, findsOneWidget);
      expect(find.descendant(of: pill, matching: find.text('EN')), findsOneWidget);
      expect(find.byTooltip('Language'), findsOneWidget);
    });

    testWidgets('is on every section, not just the first', (tester) async {
      await pumpApp(tester, workspaceServer());

      for (final tab in ['Customers', 'Contracts', 'Payments']) {
        await tapText(tester, tab, last: true);
        expect(pill, findsOneWidget, reason: '$tab has no language button');
      }
    });

    testWidgets('opens the five languages, each in its own script, with the current one marked', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tester.tap(pill);
      await settle(tester);

      expect(find.text('Choose your language'), findsOneWidget);
      for (final name in ['English', 'العربية', 'Français', 'Español', 'اردو']) {
        expect(find.text(name), findsOneWidget, reason: name);
      }
      expect(find.byIcon(Icons.check_rounded), findsOneWidget);
      expect(find.descendant(of: find.widgetWithText(InkWell, 'English'), matching: find.byIcon(Icons.check_rounded)), findsOneWidget);
    });

    testWidgets('changes the whole app at once, closes, and is remembered', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tester.tap(pill);
      await settle(tester);
      await tapText(tester, 'Español');

      expect(find.byType(LanguageSheet), findsNothing, reason: 'the sheet closes');
      expect(find.text('Panel'), findsWidgets, reason: 'the app speaks Spanish now');
      expect(find.descendant(of: pill, matching: find.text('ES')), findsOneWidget);
      expect(await savedLanguage(), 'es');
    });

    testWidgets('turns the app around for a language written right to left', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tester.tap(pill);
      await settle(tester);
      await tapText(tester, 'العربية');

      expect(Directionality.of(tester.element(find.byType(Scaffold).first)), TextDirection.rtl);
      expect(await savedLanguage(), 'ar');
    });
  });

  group('before signing in', () {
    testWidgets('the sign-in screen has it, spelled out in full', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false);

      expect(find.byType(AuthShell), findsOneWidget);
      expect(find.descendant(of: pill, matching: find.text('English')), findsOneWidget);
    });

    testWidgets('choosing Arabic there reads right to left at once', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false);
      await tester.tap(pill);
      await settle(tester);
      await tapText(tester, 'العربية');

      expect(Directionality.of(tester.element(find.byType(AuthShell))), TextDirection.rtl);
      expect(find.descendant(of: pill, matching: find.text('العربية')), findsOneWidget);
    });

    testWidgets('holds together with the text at twice the size, in the longest names', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false, language: 'es', textScale: 2, size: const Size(360, 740));

      expect(pill, findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('Settings', () {
    testWidgets('still lists the languages as a plain choice, and the two stay in step', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSettings(tester);

      expect(find.text('Language'), findsOneWidget);
      await tapText(tester, 'Français');

      expect(await savedLanguage(), 'fr');
      expect(find.descendant(of: pill, matching: find.text('FR')), findsOneWidget);
    });
  });
}
