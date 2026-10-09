import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/api/api_client.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/data/models.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// What the platform's switches look like from the phone: a feature it has turned off disappears, a feature the plan
/// lacks stays in sight with the way to upgrade, and the owner's tools are drawn from what the server describes.

Map<String, dynamic> toolsJson({bool canEdit = true}) => {
      'data': [
        {'key': 'reminders.enabled', 'feature': 'reminders', 'type': 'switch', 'label': 'Send reminders', 'help': 'Remind customers before an instalment is due.', 'value': true},
        {'key': 'reports.window_days', 'feature': 'advanced_reports', 'type': 'int', 'label': 'Report window', 'help': 'How many days a report looks back.', 'value': 30},
        {
          'key': 'reports.number_style',
          'feature': 'advanced_reports',
          'type': 'select',
          'label': 'Number style',
          'help': '',
          'value': 'western',
          'options': [
            {'value': 'western', 'label': '1,234'},
            {'value': 'eastern', 'label': '١٬٢٣٤'},
          ],
        },
      ],
      'meta': {'can_edit': canEdit},
    };

FakeServer withTools({bool canEdit = true, Map<String, Route> routes = const {}}) => workspaceServer(routes: {
      'GET /settings/tools': always(json(200, toolsJson(canEdit: canEdit))),
      ...routes,
    });

Future<void> openTools(WidgetTester tester) async {
  await openSettings(tester);
  await tapText(tester, 'Instalment tools');
}

ApiClient clientFor(FakeAdapter adapter, {void Function()? onFeatureUnavailable}) => ApiClient(
      baseUrl: 'https://qistas.test/api/v1',
      tokens: MemoryTokenStore('qst_secret-token'),
      language: () => 'en',
      onUnauthorized: () {},
      onFeatureUnavailable: onFeatureUnavailable,
      adapter: adapter,
    );

void main() {
  group('what the platform says about a feature', () {
    Map<String, dynamic> with_(Map<String, dynamic> entitlement) => {
          ...accountJson(),
          'entitlements': {...entitlementsJson(), 'export_csv': entitlement},
        };

    test('an entitlement carries its status and, when another feature is the reason, which one', () {
      final e = Entitlement.fromJson({'type': 'toggle', 'enabled': false, 'status': 'platform_off', 'detail': 'dependency:advanced_reports'});

      expect(e.status, 'platform_off');
      expect(e.detail, 'dependency:advanced_reports');
      expect(e.isPlatformOff, isTrue);
      expect(e.isOn, isFalse);
    });

    test('an older server sends no status: what it calls enabled is on, and what it does not is a plan that lacks it', () {
      expect(Entitlement.fromJson({'type': 'toggle', 'enabled': true}).status, 'on');
      expect(Entitlement.fromJson({'type': 'toggle', 'enabled': false}).status, 'plan_locked');
      expect(Entitlement.fromJson({'type': 'limit', 'enabled': true, 'limit': 5, 'used': 1}).isPlatformOff, isFalse);
    });

    test('only a feature the platform has switched off is hidden', () {
      final off = Account.fromJson(with_({'type': 'toggle', 'enabled': false, 'status': 'platform_off'}));
      final locked = Account.fromJson(with_({'type': 'toggle', 'enabled': false, 'status': 'plan_locked'}));
      final on = Account.fromJson(with_({'type': 'toggle', 'enabled': true, 'status': 'on'}));

      expect(off.shows('export_csv'), isFalse);
      expect(locked.shows('export_csv'), isTrue, reason: 'locked by the plan: shown, with the way to upgrade');
      expect(on.shows('export_csv'), isTrue);
    });

    test('a feature the app has never heard of is shown rather than guessed away', () {
      expect(sampleAccount().shows('something_new'), isTrue);
    });
  });

  group('the app is told when the platform switches something off', () {
    test('a 403 feature_unavailable is reported, once however often it is asked within ten seconds', () async {
      var told = 0;
      final adapter = FakeAdapter((_) => apiError(403, 'feature_unavailable', 'This is not available right now.', extra: {'feature': 'advanced_reports'}));
      final client = clientFor(adapter, onFeatureUnavailable: () => told++);

      for (var i = 0; i < 3; i++) {
        await client.get('/reports').then<void>((_) {}, onError: (Object _) {});
      }

      expect(told, 1);
    });

    test('a plan that lacks a feature (402) is not the platform switching it off', () async {
      var told = 0;
      final adapter = FakeAdapter((_) => apiError(402, 'feature_locked', 'Not in your plan.', extra: {'feature': 'export_csv'}));

      await clientFor(adapter, onFeatureUnavailable: () => told++).get('/export').then<void>((_) {}, onError: (Object _) {});

      expect(told, 0);
    });

    test('an ordinary 403 (not allowed for this role) is not either', () async {
      var told = 0;
      final adapter = FakeAdapter((_) => apiError(403, 'forbidden', 'Not allowed.'));

      await clientFor(adapter, onFeatureUnavailable: () => told++).get('/settings').then<void>((_) {}, onError: (Object _) {});

      expect(told, 0);
    });
  });

  group('the owner’s tools', () {
    testWidgets('are not in Settings while the server lists none', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSettings(tester);

      expect(find.text('Sign out'), findsWidgets);
      expect(find.text('Instalment tools'), findsNothing);
    });

    testWidgets('are drawn from what the server describes: a switch, a number and a choice', (tester) async {
      await pumpApp(tester, withTools());
      await openTools(tester);

      for (final label in ['Send reminders', 'Report window', 'Number style']) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
      expect(find.text('Remind customers before an instalment is due.'), findsOneWidget);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text, '30');
      expect(find.byType(ChoiceChip), findsNWidgets(2));
      expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, '1,234')).selected, isTrue);
    });

    testWidgets('a switch saves the moment it is flipped', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reminders.enabled': always(json(200, {'data': {...(toolsJson()['data'] as List).first as Map<String, dynamic>, 'value': false}})),
      });
      await pumpApp(tester, server);
      await openTools(tester);

      await tester.tap(find.byType(Switch));
      await settle(tester);

      expect(server.requestsTo('PUT /settings/tools/reminders.enabled'), hasLength(1));
      expect(server.adapter.lastBody, {'value': false});
      expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
      expect(find.text('Saved'), findsOneWidget);
    });

    testWidgets('a number is sent as a whole number, and only once it has changed', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reports.window_days': always(json(200, {'data': {...(toolsJson()['data'] as List)[1] as Map<String, dynamic>, 'value': 45}})),
      });
      await pumpApp(tester, server);
      await openTools(tester);

      expect(tester.widget<QButton>(find.widgetWithText(QButton, 'Save')).onPressed, isNull, reason: 'nothing to save yet');

      await tester.enterText(find.byType(TextField), '45');
      await tester.pump();
      await tapButton(tester, 'Save');

      expect(server.adapter.lastBody, {'value': 45});
      expect(find.text('Saved'), findsOneWidget);
    });

    testWidgets('a choice saves when it is tapped', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reports.number_style': always(json(200, {'data': {...(toolsJson()['data'] as List)[2] as Map<String, dynamic>, 'value': 'eastern'}})),
      });
      await pumpApp(tester, server);
      await openTools(tester);

      await tapText(tester, '١٬٢٣٤');

      expect(server.adapter.lastBody, {'value': 'eastern'});
      expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, '١٬٢٣٤')).selected, isTrue);
    });

    testWidgets('a refused value puts the old one back and says why', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reports.window_days': always(apiError(422, 'validation_failed', 'Not valid.', extra: {'fields': {'value': ['Choose a number from 1 to 365.']}})),
      });
      await pumpApp(tester, server);
      await openTools(tester);

      await tester.enterText(find.byType(TextField), '900');
      await tester.pump();
      await tapButton(tester, 'Save');

      expect(find.text('Choose a number from 1 to 365.'), findsOneWidget);
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text, '30');
    });

    testWidgets('are read-only for someone who may not change them, who is told so', (tester) async {
      final server = withTools(canEdit: false);
      await pumpApp(tester, server);
      await openTools(tester);

      expect(find.text('Only the owner and managers can change these.'), findsOneWidget);
      expect(tester.widget<Switch>(find.byType(Switch)).onChanged, isNull);

      await tester.tap(find.byType(Switch), warnIfMissed: false);
      await settle(tester);
      expect(server.calls('PUT /settings/tools/reminders.enabled'), 0);
    });

    testWidgets('when the platform switches a feature off, the old value returns, the account is read again and the list is refreshed', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reminders.enabled': always(apiError(403, 'feature_unavailable', 'Reminders are not available right now.', extra: {'feature': 'reminders'})),
      });
      await pumpApp(tester, server);
      await openTools(tester);
      final accountReads = server.calls('GET /me');
      final listReads = server.calls('GET /settings/tools');

      await tester.tap(find.byType(Switch));
      await settle(tester);

      expect(server.calls('GET /me'), accountReads + 1);
      expect(server.calls('GET /settings/tools'), listReads + 1);
    });

    testWidgets('a plan that lacks the feature gets the upgrade sheet, not a generic error', (tester) async {
      final server = withTools(routes: {
        'PUT /settings/tools/reminders.enabled': always(apiError(402, 'feature_locked', 'Reminders are part of Pro.', extra: {'feature': 'reminders'})),
      });
      await pumpApp(tester, server);
      await openTools(tester);

      await tester.tap(find.byType(Switch));
      await settle(tester);

      expect(find.text('This is part of Pro'), findsOneWidget);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
    });
  });
}
