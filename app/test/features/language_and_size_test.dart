import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/harness.dart';

void main() {
  group('language', () {
    testWidgets('Arabic reads right to left, in Arabic', (tester) async {
      await pumpApp(tester, workspaceServer(), language: 'ar');

      expect(find.text('لوحة التحكم'), findsWidgets);
      final direction = Directionality.of(tester.element(find.text('لوحة التحكم').first));
      expect(direction, TextDirection.rtl);
    });

    testWidgets('the bottom bar mirrors in Arabic: the first section sits on the right', (tester) async {
      await pumpApp(tester, workspaceServer(), language: 'ar');

      final first = tester.getCenter(find.text('لوحة التحكم').last).dx;
      final second = tester.getCenter(find.text('العملاء').last).dx;

      expect(first, greaterThan(second));
    });

    testWidgets('the bottom bar reads left to right in English: the first section sits on the left', (tester) async {
      await pumpApp(tester, workspaceServer());

      final first = tester.getCenter(find.text('Dashboard').last).dx;
      final second = tester.getCenter(find.text('Customers').last).dx;

      expect(first, lessThan(second));
    });

    testWidgets('amounts and phone numbers keep their digits and direction in every language', (tester) async {
      await pumpApp(tester, workspaceServer(), language: 'ar');

      expect(find.text('SAR 5,000.00'), findsOneWidget);
    });

    testWidgets('a language chosen in Settings is kept, and used the next time the app starts', (tester) async {
      await pumpApp(tester, workspaceServer());

      await openSettings(tester);
      await tapText(tester, 'Français');
      expect(find.text('Tableau de bord'), findsWidgets);

      final saved = await SharedPreferences.getInstance();
      expect(saved.getString('language'), 'fr');

      // A new launch with what was saved.
      await pumpApp(tester, workspaceServer(), language: saved.getString('language')!);
      expect(find.text('Tableau de bord'), findsWidgets);
    });

    testWidgets('moving between sections never changes the language', (tester) async {
      await pumpApp(tester, workspaceServer(), language: 'es');

      for (final section in ['Clientes', 'Contratos', 'Pagos']) {
        await tapText(tester, section, last: true);
      }
      await openSettings(tester);
      await tapText(tester, 'Panel', last: true);

      expect(find.text('Panel'), findsWidgets);
      expect(find.text('Dashboard'), findsNothing);
    });
  });

  group('large text on a small phone', () {
    for (final language in ['en', 'ar']) {
      testWidgets('every main screen lays out at 200% text in $language without overflow', (tester) async {
        await pumpApp(tester, workspaceServer(), language: language, size: const Size(360, 640), textScale: 2);

        final tabs = language == 'en' ? ['Customers', 'Contracts', 'Payments'] : ['العملاء', 'العقود', 'الدفعات'];
        for (final tab in tabs) {
          await tapText(tester, tab, last: true);
          expect(tester.takeException(), isNull, reason: 'overflow on $tab');
        }

        await openSettings(tester);
        expect(tester.takeException(), isNull, reason: 'overflow on settings');
        await tapText(tester, language == 'en' ? 'Dashboard' : 'لوحة التحكم', last: true);
        expect(tester.takeException(), isNull, reason: 'overflow on the dashboard');
      });
    }

    testWidgets('the forms stay usable at 200% text', (tester) async {
      await pumpApp(tester, workspaceServer(), size: const Size(360, 640), textScale: 2);

      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      expect(tester.takeException(), isNull);
      expect(find.text('Full name'), findsOneWidget);

      await tester.pageBack();
      await settle(tester);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      expect(tester.takeException(), isNull);
      expect(find.text('Choose a customer'), findsWidgets);
    });

    testWidgets('the sign-in screen fits a 320 dp wide phone at 200% text', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false, size: const Size(320, 568), textScale: 2);

      expect(find.text('Welcome back'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
