import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';
import '../support/visual.dart';

/// Pictures of the main screens in the real fonts. Run with QISTAS_SCREENSHOTS set to a folder; skipped otherwise.
void main() {
  const tall = Size(412, 1500);

  /// One picture test. The test engine draws shadows as hard shapes unless told otherwise; the pictures should show
  /// the real thing, and the flag is put back before the framework checks that a test left nothing changed.
  void picture(String name, Future<void> Function(WidgetTester tester) body) => testWidgets(
        name,
        (tester) async {
          debugDisableShadows = false;
          try {
            await body(tester);
          } finally {
            debugDisableShadows = true;
          }
        },
        skip: screenshotFolder == null,
      );

  Future<void> openContractForm(WidgetTester tester) async {
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');
    await tapText(tester, 'Choose a customer');
    await tapText(tester, 'Ahmad Salem');
  }

  picture('new contract, every rhythm with grace days', (tester) async {
    await pumpApp(tester, workspaceServer(account: flexibleAccountJson()), realFonts: true, size: const Size(360, 2000));
    await openContractForm(tester);
    await typeInto(tester, 'Sale price (SAR)', '12000');
    await tapText(tester, 'Three months');
    await tapTooltip(tester, 'More grace days');
    await tapTooltip(tester, 'More grace days');
    await snapshot(tester, 'contract-form-flexible');
  });

  picture('new contract on the shop’s own dates, Arabic', (tester) async {
    await pumpApp(tester, workspaceServer(account: flexibleAccountJson()), realFonts: true, size: const Size(360, 2200), language: 'ar');
    await tapText(tester, 'العقود', last: true);
    await tapText(tester, 'عقد جديد');
    await tapText(tester, 'اختر عميلًا');
    await tapText(tester, 'Ahmad Salem');
    await typeInto(tester, 'سعر البيع (SAR)', '600');
    await tapText(tester, 'تواريخ أختارها');
    await tapTooltip(tester, 'إزالة هذا التاريخ');
    await snapshot(tester, 'contract-form-own-dates-ar');
  });

  picture('investors, Arabic', (tester) async {
    await pumpApp(
      tester,
      workspaceServer(account: accountWithInvestors(plan: 'pro'), routes: {'GET /investors': always(json(200, {'data': investorsJson(partner: true, limit: null)}))}),
      realFonts: true,
      size: const Size(360, 1100),
      language: 'ar',
    );
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-investors')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-investors')));
    await settle(tester);
    await snapshot(tester, 'investors-ar');
  });

  picture('one investor', (tester) async {
    await pumpApp(
      tester,
      workspaceServer(account: accountWithInvestors(), routes: {
        'GET /investors': always(json(200, {'data': investorsJson()})),
        'GET /investors/i1': always(json(200, {'data': investorDetailJson()})),
      }),
      realFonts: true,
      size: const Size(360, 1700),
    );
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-investors')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-investors')));
    await settle(tester);
    await tapText(tester, 'Own capital');
    await snapshot(tester, 'investor-detail');
  });

  picture('sign-in', (tester) async {
    await pumpApp(tester, workspaceServer(routes: {'GET /demo': always(json(200, {'data': demoOfferJson()}))}), signedIn: false, realFonts: true);
    await snapshot(tester, 'login');
  });

  picture('dashboard', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: tall);
    await snapshot(tester, 'dashboard');
  });

  // The look of an event (Saudi National Day's green) and its welcome banner, as the server would send them.
  final event = {
    'version': 4, 'custom': true,
    'tokens': {
      'light': {'primary': '#006C35', 'onPrimary': '#F7F3EA', 'action': '#006C35', 'onAction': '#F7F3EA', 'accent': '#C8A951', 'onAccent': '#0B1F44', 'accentText': '#7A6224', 'info': '#0B6E4F', 'onInfo': '#FFFFFF', 'bg': '#F4F8F4', 'surface': '#FFFFFF', 'surfaceAlt': '#E8EFE8', 'heroFrom': '#006C35', 'heroTo': '#00863F'},
      'dark': {'primary': '#1E6B45', 'onPrimary': '#F7F3EA', 'action': '#C8A951', 'onAction': '#0B1F44', 'accent': '#D4B865', 'onAccent': '#0B1F44', 'bg': '#06190F', 'surface': '#0D2A1B', 'surfaceAlt': '#123624', 'heroFrom': '#1F7A4D', 'heroTo': '#0F4A2D', 'line': '#1E4632'},
    },
    'banner': {'title': 'Happy Saudi National Day', 'message': 'Celebrating the Kingdom with you.', 'cta_label': 'See plans', 'cta_url': '/pricing', 'tone': 'navy', 'dismissible': true, 'image_url': null, 'ends_on': null, 'key': 'nd'},
    'event': {'id': 'e1', 'name': 'Saudi National Day', 'ends_on': '2999-09-24', 'until': '2999-09-24T21:00:00+00:00'},
    'base': null,
  };

  picture('dashboard dressed for an event', (tester) async {
    await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': event}))}), realFonts: true, size: tall);
    await snapshot(tester, 'dashboard-event');
  });

  picture('dashboard dressed for an event, Arabic, dark', (tester) async {
    final arabic = {...event, 'banner': {...event['banner']! as Map<String, dynamic>, 'title': 'كل عام والوطن بخير', 'message': 'نحتفل معكم باليوم الوطني السعودي.', 'cta_label': 'الخطط'}};
    await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': arabic}))}), realFonts: true, size: tall, language: 'ar', preferences: {'theme_mode': 'dark'});
    await snapshot(tester, 'dashboard-event-ar-dark');
  });

  picture('dashboard in Arabic', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: tall, language: 'ar');
    await snapshot(tester, 'dashboard-ar');
  });

  picture('dashboard on a small phone', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: const Size(360, 1300));
    await snapshot(tester, 'dashboard-small');
  });

  picture('customers', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true);
    await tapText(tester, 'Customers', last: true);
    await snapshot(tester, 'customers');
    await tapText(tester, 'Ahmad Salem');
    await snapshot(tester, 'customer');
  });

  picture('contracts', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: const Size(412, 1200));
    await tapText(tester, 'Contracts', last: true);
    await snapshot(tester, 'contracts');
    await tapText(tester, 'C-0007');
    await snapshot(tester, 'contract');
  });

  picture('payments', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true);
    await tapText(tester, 'Payments', last: true);
    await snapshot(tester, 'payments');
  });

  picture('settings', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: tall);
    await openSettings(tester);
    await snapshot(tester, 'settings');
  });

  picture('settings in Arabic, dark', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountJson(plan: 'pro')), realFonts: true, size: tall, language: 'ar', preferences: {'theme_mode': 'dark'});
    await openSettings(tester);
    await snapshot(tester, 'settings-ar-dark');
  });

  picture('quick actions', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true);
    await tapTooltip(tester, 'Quick actions');
    await snapshot(tester, 'quick-actions');
  });

  picture('search', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true);
    await tapTooltip(tester, 'Search');
    await snapshot(tester, 'search');
  });

  picture('reminder', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: tall);
    await tapText(tester, 'Remind');
    await snapshot(tester, 'reminder');
  });

  picture('language sheet', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true);
    await tapTooltip(tester, 'Language');
    await snapshot(tester, 'language-sheet');
  });

  picture('language sheet in Arabic', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, language: 'ar');
    await tapTooltip(tester, 'اللغة');
    await snapshot(tester, 'language-sheet-ar');
  });

  picture('instalment tools', (tester) async {
    final tools = {
      'data': [
        {'key': 'reminders.enabled', 'feature': 'reminders', 'type': 'switch', 'label': 'Send reminders', 'help': 'Remind customers before an instalment is due.', 'value': true},
        {'key': 'reports.window_days', 'feature': 'advanced_reports', 'type': 'int', 'label': 'Report window', 'help': 'How many days a report looks back.', 'value': 30},
        {
          'key': 'reports.number_style', 'feature': 'advanced_reports', 'type': 'select', 'label': 'Number style', 'help': 'How amounts are written in reports.', 'value': 'western',
          'options': [
            {'value': 'western', 'label': '1,234'},
            {'value': 'eastern', 'label': '١٬٢٣٤'},
          ],
        },
      ],
      'meta': {'can_edit': true},
    };
    await pumpApp(tester, workspaceServer(routes: {'GET /settings/tools': always(json(200, tools))}), realFonts: true, size: tall);
    await openSettings(tester);
    await snapshot(tester, 'settings-with-tools');
    await tapText(tester, 'Instalment tools');
    await snapshot(tester, 'tools');
  });

  picture('payment recorded', (tester) async {
    final server = workspaceServer(routes: {'POST /contracts/k1/payments': always(json(201, {'data': lineJson()}))});
    await pumpApp(tester, server, realFonts: true);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');
    await tapText(tester, 'Record a payment', last: true);
    await snapshot(tester, 'record-sheet');
    await tapButton(tester, 'Record payment');
    await snapshot(tester, 'payment-recorded');
  });

  // The app lock: what a locked Qistas shows, by day, by night and in Arabic; and its settings screen.
  for (final (name, language, mode) in [('app-lock', 'en', 'light'), ('app-lock-dark', 'en', 'dark'), ('app-lock-ar', 'ar', 'light')]) {
    picture(name, (tester) async {
      await pumpApp(tester, workspaceServer(), realFonts: true, language: language, deviceAuth: FakeDeviceAuth(answers: [false]),
          preferences: {'app_lock.enabled': true, 'theme_mode': mode});
      await snapshot(tester, name);
    });
  }

  picture('app lock settings', (tester) async {
    await pumpApp(tester, workspaceServer(), realFonts: true, size: tall, deviceAuth: FakeDeviceAuth(), preferences: {'app_lock.enabled': true});
    await openSettings(tester);
    await tapText(tester, 'App lock');
    await snapshot(tester, 'app-lock-settings');
  });
}
