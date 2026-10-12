import 'dart:convert';

import 'package:dio/dio.dart' show RequestOptions;
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Sprint 3 Task 1b: every kind of notice (instalment alerts, billing, support) reaches a person in the app, by e-mail or
/// by text message, as they choose among what the admin allows and what is set up. Billing notices are for owners.

Map<String, dynamic> kindJson(String key, String group, String label) => {'key': key, 'group': group, 'label': label, 'description': '$label, explained.'};

Map<String, dynamic> channelsPreferencesJson({bool email = true, bool sms = false, Map<String, Map<String, bool>> allowed = const {}}) {
  const app = {'push': true, 'email': false, 'sms': false};
  const appAndEmail = {'push': true, 'email': true, 'sms': false};
  const kinds = {
    'daily_digest': ('alerts', 'Morning summary'),
    'instalment_due': ('alerts', 'On the due date'),
    'instalment_late': ('alerts', 'When an instalment is late'),
    'billing_renewal_due': ('billing', 'Before your plan ends'),
    'billing_renewed': ('billing', 'When your plan renews'),
    'billing_payment_failed': ('billing', 'When a payment fails'),
    'billing_expired': ('billing', 'When your plan ends'),
    'support_reply': ('support', 'Support replies'),
  };

  return {
    'data': {
      'daily_digest': {'enabled': true, 'time': '09:00', 'channels': app},
      'instalment_due': {'enabled': false, 'channels': app},
      'instalment_late': {'enabled': true, 'repeat': 'weekly', 'channels': app},
      for (final key in kinds.keys.where((k) => kinds[k]!.$1 != 'alerts')) key: {'enabled': true, 'channels': appAndEmail},
      'quiet_hours': {'enabled': false, 'from': '22:00', 'to': '08:00'},
    },
    'meta': {
      'kinds': [for (final MapEntry(:key, :value) in kinds.entries) kindJson(key, value.$1, value.$2)],
      'channels': {
        'push': {'available': true, 'label': 'In the app'},
        'email': {'available': email, 'label': 'By e-mail'},
        'sms': {'available': sms, 'label': 'By text message'},
      },
      'allowed': {for (final key in kinds.keys) key: allowed[key] ?? {'push': true, 'email': true, 'sms': true}},
    },
  };
}

/// An account whose platform has instalment alerts and the morning summary on, or not.
Map<String, dynamic> noticesAccount({String role = 'owner', bool alerts = false}) {
  final account = accountJson(role: role);
  if (alerts) {
    Map<String, dynamic> on() => {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false};
    account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map), 'instalment_alerts': on(), 'daily_digest': on()};
  }

  return account;
}

FakeServer channelsServer({String role = 'owner', bool alerts = false, Map<String, dynamic>? preferences}) =>
    workspaceServer(account: noticesAccount(role: role, alerts: alerts), routes: {
      'GET /notifications/preferences': always(json(200, preferences ?? channelsPreferencesJson())),
      'PUT /notifications/preferences': (RequestOptions options) => json(200, {...(preferences ?? channelsPreferencesJson()), 'data': jsonDecode(options.data as String)}),
    });

Map<String, dynamic> lastSaved(FakeServer server) => jsonDecode(server.requestsTo('PUT /notifications/preferences').last.data as String) as Map<String, dynamic>;

Future<void> openNotices(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-alerts')));
  await tester.pump();
  await tester.tap(find.byKey(const ValueKey('settings-alerts')));
  await settle(tester);
}

Future<void> tapChip(WidgetTester tester, String kind, String channel) async {
  final chip = find.byKey(ValueKey('notice-$kind-$channel'));
  await tester.scrollUntilVisible(chip, 200, scrollable: find.byType(Scrollable).first);
  await tester.ensureVisible(chip);
  await tester.pump();
  await tester.tap(chip);
  await settle(tester);
}

void main() {
  testWidgets('is in Settings even while instalment alerts are switched off, for billing and support notices', (tester) async {
    await pumpApp(tester, channelsServer(), size: const Size(412, 2000));
    await openNotices(tester);

    expect(find.text('How notices reach you'), findsOneWidget);
    expect(find.text('Your plan and payments'), findsOneWidget);
    expect(find.text('Before your plan ends'), findsOneWidget);
    // Alerts the platform has switched off are not offered at all.
    expect(find.byKey(const ValueKey('notice-instalment_late-push')), findsNothing);
  });

  testWidgets('sends a notice by e-mail as well, or not, as the person chooses', (tester) async {
    final server = channelsServer(alerts: true);
    await pumpApp(tester, server, size: const Size(412, 2000));
    await openNotices(tester);

    await tapChip(tester, 'billing_renewed', 'email');
    expect(lastSaved(server)['billing_renewed'], {'enabled': true, 'channels': {'push': true, 'email': false, 'sms': false}});

    await tapChip(tester, 'instalment_late', 'email');
    expect((lastSaved(server)['instalment_late'] as Map)['channels'], {'push': true, 'email': true, 'sms': false});
  });

  testWidgets('switches a kind off altogether', (tester) async {
    final server = channelsServer();
    await pumpApp(tester, server, size: const Size(412, 2000));
    await openNotices(tester);

    final toggle = find.byKey(const ValueKey('notice-billing_expired'));
    await tester.scrollUntilVisible(toggle, 200, scrollable: find.byType(Scrollable).first);
    await tester.tap(toggle);
    await settle(tester);

    expect((lastSaved(server)['billing_expired'] as Map)['enabled'], false);
  });

  testWidgets('offers only the channels that are set up and that the admin allows', (tester) async {
    await pumpApp(
      tester,
      channelsServer(preferences: channelsPreferencesJson(allowed: {'support_reply': {'push': true, 'email': false, 'sms': false}})),
      size: const Size(412, 2000),
    );
    await openNotices(tester);

    expect(find.byKey(const ValueKey('notice-billing_renewed-email')), findsOneWidget);
    expect(find.byKey(const ValueKey('notice-billing_renewed-sms')), findsNothing);
    expect(find.byKey(const ValueKey('notice-support_reply-email')), findsNothing);
    expect(find.text('By text message: not available yet.'), findsOneWidget);
  });

  testWidgets('shows billing notices to owners only', (tester) async {
    await pumpApp(tester, channelsServer(role: 'collector'), size: const Size(412, 2000));
    await openNotices(tester);

    expect(find.text('Your plan and payments'), findsNothing);
    expect(find.text('Support replies'), findsOneWidget);
  });
}
