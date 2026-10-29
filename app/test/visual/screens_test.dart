import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:qistas/features/glance/glance_service.dart';
import 'package:qistas/features/glance/glance_store.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/fake_glance_store.dart';
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

  picture('open account, Arabic', (tester) async {
    final account = accountJson();
    account['entitlements'] = {
      ...Map<String, dynamic>.from(account['entitlements'] as Map),
      'open_contracts': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
    };
    Map<String, dynamic> line(String id, String type, String amount, String after, {String? tag}) => {
          'id': id, 'type': type, 'method': 'cash', 'amount': amount, 'paid_at': '2026-10-05T10:00:00Z', 'note': null, 'tag': tag,
          'voided': false, 'reverses_transaction_id': null, 'balance_after': after,
        };
    final open = {
      ...contractJson(),
      'type': 'open', 'credit_limit': '500.00', 'owed': '225.50', 'next_installment': null, 'installments': <Object?>[], 'installment_count': 0,
      'transactions': [line('t3', 'charge', '45.50', '225.50', tag: 'unpaid'), line('t2', 'payment', '120.00', '180.00'), line('t1', 'charge', '300.00', '300.00')],
    };
    await pumpApp(tester, workspaceServer(account: account, routes: {'GET /contracts/k1': always(json(200, {'data': open}))}), realFonts: true, size: const Size(360, 1300), language: 'ar');
    await tapText(tester, 'العقود', last: true);
    await tapText(tester, 'C-0007');
    await snapshot(tester, 'open-account-ar');
  });

  Map<String, dynamic> withProfile(String language) {
    final account = accountJson();
    account['user'] = {...Map<String, dynamic>.from(account['user'] as Map), 'phone': '+966501234567', 'member_since': '2026-10-08', 'locale': language};

    return account;
  }

  for (final language in ['en', 'ar']) {
    picture('your profile, $language', (tester) async {
      await pumpApp(tester, workspaceServer(account: withProfile(language)), realFonts: true, size: const Size(390, 1900), language: language);
      await openSettings(tester);
      await tester.tap(find.byKey(const ValueKey('settings-profile')));
      await settle(tester);
      await snapshot(tester, 'profile-$language');
    });
  }

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

  // The end of the day (daily-habit wave A, H4): the till being counted, balanced and short; the day closed; and Today's tile.
  Future<void> typeCount(WidgetTester tester, String keys) async {
    for (final key in keys.split('')) {
      final pad = find.byKey(ValueKey(key == '.' ? 'pad-point' : 'pad-$key'));
      await tester.ensureVisible(pad);
      await tester.pump();
      await tester.tap(pad);
      await tester.pump();
    }
    // The button warms from disabled to ready over a moment: wait for it before taking the picture.
    await settle(tester);
  }

  FakeServer dayCloseServer({bool closed = false, String difference = '0.00'}) => workspaceServer(account: accountWithDayClose(), routes: {
        'GET /day-close/today': always(json(200, {
          'data': dayCloseTodayJson(
            closed: closed,
            close: closed ? dayCloseJson(counted: difference == '0.00' ? '3100.00' : '3080.00', difference: difference, note: difference == '0.00' ? null : 'Two torn notes put aside') : null,
            canClose: !closed,
            canReopen: closed,
          ),
        })),
        'POST /day-close': always(json(201, {'data': dayCloseJson()})),
      });

  for (final (name, language, keys, mode) in [
    ('day-close-balanced', 'en', '3100', 'light'),
    ('day-close-short', 'en', '3080.5', 'light'),
    ('day-close-balanced-ar', 'ar', '3100', 'light'),
    ('day-close-short-ar-dark', 'ar', '3080.5', 'dark'),
  ]) {
    picture(name, (tester) async {
      await pumpApp(tester, dayCloseServer(), realFonts: true, size: const Size(390, 2150), language: language, preferences: {'theme_mode': mode});
      await tester.tap(find.byKey(const ValueKey('day-close-tile')));
      await settle(tester);
      await typeCount(tester, keys);
      await snapshot(tester, name);
    });
  }

  for (final (name, language, difference, mode) in [
    ('day-close-closed', 'en', '0.00', 'light'),
    ('day-close-closed-short', 'en', '-20.00', 'light'),
    ('day-close-closed-ar-dark', 'ar', '0.00', 'dark'),
  ]) {
    picture(name, (tester) async {
      await pumpApp(tester, dayCloseServer(closed: true, difference: difference), realFonts: true, size: const Size(390, 1900), language: language, preferences: {'theme_mode': mode});
      await tester.tap(find.byKey(const ValueKey('day-close-tile')));
      await settle(tester);
      await snapshot(tester, name);
    });
  }

  picture('day-close-moment', (tester) async {
    await pumpApp(tester, dayCloseServer(), realFonts: true, size: const Size(390, 900));
    await tester.tap(find.byKey(const ValueKey('day-close-tile')));
    await settle(tester);
    await typeCount(tester, '3100');
    await tester.tap(find.byKey(const ValueKey('close-day-button')));
    await settle(tester);
    await snapshot(tester, 'day-close-moment');
  });

  picture('day-close-moment-ar', (tester) async {
    await pumpApp(tester, dayCloseServer(), realFonts: true, size: const Size(390, 900), language: 'ar');
    await tester.tap(find.byKey(const ValueKey('day-close-tile')));
    await settle(tester);
    await typeCount(tester, '3100');
    await tester.tap(find.byKey(const ValueKey('close-day-button')));
    await settle(tester);
    await snapshot(tester, 'day-close-moment-ar');
  });

  for (final (name, closed, language, hour) in [('dashboard-day-close-evening', false, 'en', 21), ('dashboard-day-close-morning-ar', false, 'ar', 9), ('dashboard-day-close-closed', true, 'en', 21)]) {
    picture(name, (tester) async {
      await pumpApp(tester, dayCloseServer(closed: closed), realFonts: true, size: const Size(390, 900), language: language, clock: FakeClock(DateTime(2026, 10, 21, hour)));
      await snapshot(tester, name);
    });
  }

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

  // The home-screen widget's settings: its two switches, and with the app lock on the reasons they cannot be changed.
  for (final (name, language, mode, lock) in [('widget-settings', 'en', 'light', false), ('widget-settings-ar-dark-locked', 'ar', 'dark', true)]) {
    picture(name, (tester) async {
      await pumpApp(tester, workspaceServer(account: accountWithWidget()), realFonts: true, language: language, deviceAuth: FakeDeviceAuth(),
          overrides: [glanceSupportedProvider.overrideWithValue(true), glanceStoreProvider.overrideWithValue(FakeGlanceStore())],
          preferences: {'theme_mode': mode, if (lock) 'app_lock.enabled': true, 'glance.show_names': true});
      await openSettings(tester);
      await tapText(tester, language == 'en' ? 'Home-screen widget' : 'أداة الشاشة الرئيسية');
      await snapshot(tester, name);
    });
  }
  // Payday learning: the chip on a customer's page, and the offer of their usual payday under the first due date. The server writes
  // the chip's words in the language of the request; the sentence under the date is the app's own.
  for (final (name, language, label, basis, customerName) in [
    ('payday-customer', 'en', 'Usually pays around day 27 of the month', 'Seen in 83% of recent months', 'Ahmad Salem'),
    ('payday-customer-ar', 'ar', 'عادة ما يدفع حوالي يوم 27 من الشهر', 'لوحظ في 83% من الأشهر الأخيرة', 'أحمد سالم'),
  ]) {
    picture(name, (tester) async {
      final server = workspaceServer(account: accountWithPayday(), routes: {
        'GET /customers': always(json(200, pageJson([customerJson(name: customerName)], page: 1, last: 1, total: 1))),
        'GET /customers/c1': always(json(200, {'data': {...customerJson(name: customerName), 'contracts': <Map<String, dynamic>>[contractJson()], 'pay_pattern': payPatternJson(label: label, basis: basis)}})),
      });
      await pumpApp(tester, server, realFonts: true, size: const Size(360, 900), language: language);
      await tapText(tester, language == 'en' ? 'Customers' : 'العملاء', last: true);
      await tapText(tester, customerName);
      await snapshot(tester, name);
    });
  }

  for (final (name, language, customerName) in [('payday-suggestion', 'en', 'Ahmad Salem Abdullah Al-Ghamdi'), ('payday-suggestion-ar', 'ar', 'أحمد سالم عبد الله الغامدي')]) {
    picture(name, (tester) async {
      final server = workspaceServer(account: accountWithPayday(), routes: {
        'GET /customers': always(json(200, pageJson([customerJson(name: customerName)], page: 1, last: 1, total: 1))),
        'GET /customers/c1': always(json(200, {'data': {...customerJson(name: customerName), 'contracts': <Map<String, dynamic>>[], 'pay_pattern': payPatternJson()}})),
      });
      await pumpApp(tester, server, realFonts: true, size: const Size(360, 1500), language: language);
      await tapText(tester, language == 'en' ? 'Contracts' : 'العقود', last: true);
      await tapText(tester, language == 'en' ? 'New contract' : 'عقد جديد');
      await tapText(tester, language == 'en' ? 'Choose a customer' : 'اختر عميلًا');
      await tapText(tester, customerName);
      await snapshot(tester, name);
    });
  }
  // The change-plan screen's "move one date": its date row and, under it, the offer of the customer's usual payday. At the normal
  // text size, and at 200% in Arabic (where the date wraps beside its label instead of pushing it out).
  for (final (name, language, scale, size, customerName) in [
    ('change-plan-move', 'en', 1.0, const Size(360, 1000), 'Ahmad Salem'),
    ('change-plan-move-ar-200', 'ar', 2.0, const Size(360, 2400), 'أحمد سالم الغامدي'),
  ]) {
    picture(name, (tester) async {
      final account = accountWithPayday();
      account['entitlements'] = {
        ...Map<String, dynamic>.from(account['entitlements'] as Map),
        'reschedule': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
      };
      final contract = {
        ...contractJson(),
        'customer': {'id': 'c1', 'name': customerName},
        'installments': [
          installmentJson(1, state: 'paid', paid: '275.00', status: 'paid'),
          {...installmentJson(2), 'due_date': '2026-12-07'},
          {...installmentJson(3), 'due_date': '2027-01-07'},
          {...installmentJson(4), 'due_date': '2027-02-07'},
        ],
      };
      final server = workspaceServer(account: account, routes: {
        'GET /contracts/k1': always(json(200, {'data': contract})),
        'GET /customers/c1': always(json(200, {'data': {...customerJson(name: customerName), 'contracts': <Map<String, dynamic>>[], 'pay_pattern': payPatternJson()}})),
      });
      await pumpApp(tester, server, realFonts: true, size: size, language: language, textScale: scale, clock: FakeClock(DateTime(2026, 10, 14, 9)));
      GoRouter.of(tester.element(find.byType(Scaffold).first)).go('/contracts/k1/plan');
      await settle(tester);
      final move = language == 'en' ? 'Move one date' : (jsonDecode(File('assets/i18n/ar.json').readAsStringSync()) as Map<String, dynamic>)['Move one date'] as String;
      await tapText(tester, move);
      await snapshot(tester, name);
    });
  }
}
