import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/language_button.dart';
import 'package:qistas/features/auth/auth_shell.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/harness.dart';

/// The language control: in the app, a small monogram circle beside the account circle; before signing in, a pill with
/// the language spelled out. Either opens five language cards, each written in itself with a greeting in it. Settings
/// has one Language row that opens the same cards.

Finder get button => find.byType(LanguageButton);

Finder card(String code) => find.byKey(ValueKey('language-$code'));

Future<String?> savedLanguage() async => (await SharedPreferences.getInstance()).getString('language');

Future<void> openSheet(WidgetTester tester) async {
  await tester.tap(button);
  await settle(tester);
}

void main() {
  group('the button in the app', () {
    testWidgets('is a monogram of the current language, on the dashboard', (tester) async {
      await pumpApp(tester, workspaceServer());

      expect(button, findsOneWidget);
      expect(find.descendant(of: button, matching: find.text('En')), findsOneWidget);
      expect(find.byTooltip('Language'), findsOneWidget);
      expect(tester.getSize(button).width, greaterThanOrEqualTo(44), reason: 'a finger must hit it');
    });

    testWidgets('is on every section, not just the first', (tester) async {
      await pumpApp(tester, workspaceServer());

      for (final tab in ['Customers', 'Contracts', 'Payments']) {
        await tapText(tester, tab, last: true);
        expect(button, findsOneWidget, reason: '$tab has no language button');
      }
    });

    testWidgets('tells a screen reader what it is and which language is on', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, workspaceServer());

      expect(tester.getSemantics(find.bySemanticsLabel('Language')), isSemantics(label: 'Language', value: 'English', isButton: true, hasTapAction: true));
      handle.dispose();
    });
  });

  group('the language cards', () {
    testWidgets('are the five languages, each in itself, with a greeting and its name in the app’s language', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSheet(tester);

      expect(find.text('Choose your language'), findsOneWidget);
      for (final (code, name, greeting, english) in [
        ('en', 'English', 'Welcome', 'English'),
        ('ar', 'العربية', 'أهلًا بك', 'Arabic'),
        ('fr', 'Français', 'Bienvenue', 'French'),
        ('es', 'Español', 'Bienvenido', 'Spanish'),
        ('ur', 'اردو', 'خوش آمدید', 'Urdu'),
      ]) {
        expect(find.descendant(of: card(code), matching: find.text(name)), findsOneWidget, reason: name);
        expect(find.descendant(of: card(code), matching: find.text(greeting)), findsOneWidget, reason: greeting);
        if (code != 'en') expect(find.descendant(of: card(code), matching: find.text(english)), findsOneWidget, reason: english);
      }
    });

    testWidgets('mark the current language, for the eye and for a screen reader', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, workspaceServer());
      await openSheet(tester);

      expect(find.descendant(of: card('en'), matching: find.byIcon(Icons.check_rounded)), findsOneWidget);
      expect(find.byIcon(Icons.check_rounded), findsOneWidget);
      expect(tester.getSemantics(find.bySemanticsLabel('English').last), isSemantics(label: 'English', isButton: true, isSelected: true, hasTapAction: true));
      expect(tester.getSemantics(find.bySemanticsLabel('Français')), isSemantics(label: 'Français', isButton: true, isSelected: false, hasTapAction: true));
      handle.dispose();
    });

    testWidgets('change the whole app at once, close, and are remembered', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSheet(tester);
      await tester.tap(card('es'));
      await settle(tester);

      expect(find.byType(LanguageSheet), findsNothing, reason: 'the cards close');
      expect(find.text('Panel'), findsWidgets, reason: 'the app speaks Spanish now');
      expect(find.descendant(of: button, matching: find.text('Es')), findsOneWidget);
      expect(await savedLanguage(), 'es');
    });

    testWidgets('turn the app around for a language written right to left', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSheet(tester);
      await tester.tap(card('ar'));
      await settle(tester);

      expect(Directionality.of(tester.element(find.byType(Scaffold).first)), TextDirection.rtl);
      expect(find.descendant(of: button, matching: find.text('ع')), findsOneWidget);
      expect(await savedLanguage(), 'ar');
    });

    testWidgets('hold together with the text at twice the size on a small phone', (tester) async {
      await pumpApp(tester, workspaceServer(), textScale: 2, size: const Size(360, 740));
      await openSheet(tester);

      expect(tester.takeException(), isNull);
      await tester.scrollUntilVisible(card('ur'), 200, scrollable: find.descendant(of: find.byType(LanguageSheet), matching: find.byType(Scrollable)));
      expect(card('ur'), findsOneWidget);
    });
  });

  group('before signing in', () {
    testWidgets('the sign-in screen has it, spelled out in full', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false);

      expect(find.byType(AuthShell), findsOneWidget);
      expect(find.descendant(of: button, matching: find.text('English')), findsOneWidget);
    });

    testWidgets('choosing Arabic there reads right to left at once', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false);
      await openSheet(tester);
      await tester.tap(card('ar'));
      await settle(tester);

      expect(Directionality.of(tester.element(find.byType(AuthShell))), TextDirection.rtl);
      expect(find.descendant(of: button, matching: find.text('العربية')), findsOneWidget);
    });

    testWidgets('holds together with the text at twice the size, in the longest names', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false, language: 'es', textScale: 2, size: const Size(360, 740));

      expect(button, findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('Settings', () {
    testWidgets('has one Language row with the current language, which opens the same cards', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSettings(tester);

      final row = find.byKey(const ValueKey('settings-language'));
      expect(find.descendant(of: row, matching: find.text('Language')), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text('English')), findsOneWidget);
      expect(find.byType(RadioListTile<String>), findsNothing, reason: 'no second, plainer list of languages');

      await tester.tap(row);
      await settle(tester);
      await tester.tap(card('fr'));
      await settle(tester);

      expect(await savedLanguage(), 'fr');
      expect(find.descendant(of: find.byKey(const ValueKey('settings-language')), matching: find.text('Français')), findsOneWidget);
      expect(find.descendant(of: button, matching: find.text('Fr')), findsOneWidget);
    });
  });
}
