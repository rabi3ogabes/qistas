import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Delete my account (Win Plan PP1; Google Play and Apple require it inside the app): the owner deletes the business
/// after typing its name, it is read-only for 30 days with a way back; anyone else deletes only their own login.

Map<String, dynamic> withTenant(Map<String, dynamic> account, Map<String, dynamic> changes) =>
    {...account, 'tenant': {...Map<String, dynamic>.from(account['tenant'] as Map), ...changes}};

Future<void> openDelete(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-delete-account')));
  await tester.tap(find.byKey(const ValueKey('settings-delete-account')));
  await settle(tester);
}

bool deleteEnabled(WidgetTester tester) => tester.widget<QButton>(find.byKey(const ValueKey('delete-confirm'))).onPressed != null;

void main() {
  testWidgets('the owner types the business name and the password, then sees the date it can be restored until', (tester) async {
    final server = workspaceServer(routes: {
      'POST /account/deletion': always(json(202, {'data': {'scope': 'workspace', 'restore_until': '2026-11-10'}})),
    });
    await pumpApp(tester, server);
    await openDelete(tester);

    expect(find.text('Delete Al-Fares Electronics and your account'), findsOneWidget);
    await typeIntoKey(tester, 'delete-password', 'secret-password');
    await typeIntoKey(tester, 'delete-confirm-name', 'Al-Fares');
    expect(deleteEnabled(tester), isFalse, reason: 'the name must match exactly');

    await typeIntoKey(tester, 'delete-confirm-name', 'Al-Fares Electronics');
    expect(deleteEnabled(tester), isTrue);

    server.on('GET /me', always(json(200, {'data': withTenant(accountJson(), {'deletion_scheduled_for': '2026-11-10'})})));
    await tester.tap(find.byKey(const ValueKey('delete-confirm')));
    await settle(tester);

    final sent = jsonDecode(server.requestsTo('POST /account/deletion').single.data as String) as Map<String, dynamic>;
    expect(sent, {'password': 'secret-password', 'confirm_name': 'Al-Fares Electronics'});
    expect(find.textContaining('2026-11-10'), findsWidgets);
  });

  testWidgets('shows the server’s answer under the password when it is wrong', (tester) async {
    final server = workspaceServer(routes: {
      'POST /account/deletion': always(json(422, {
        'error': {'code': 'validation_failed', 'message': 'Some of the information is not valid.', 'fields': {'password': ['That password is not right.']}},
      })),
    });
    await pumpApp(tester, server);
    await openDelete(tester);
    await typeIntoKey(tester, 'delete-password', 'wrong');
    await typeIntoKey(tester, 'delete-confirm-name', 'Al-Fares Electronics');

    await tester.tap(find.byKey(const ValueKey('delete-confirm')));
    await settle(tester);

    expect(find.text('That password is not right.'), findsOneWidget);
  });

  testWidgets('asks for the authenticator code when two-step sign-in is on', (tester) async {
    final account = accountJson();
    account['user'] = {...Map<String, dynamic>.from(account['user'] as Map), 'two_factor': true};
    await pumpApp(tester, workspaceServer(account: account));
    await openDelete(tester);

    expect(find.byKey(const ValueKey('delete-code')), findsOneWidget);
    await typeIntoKey(tester, 'delete-password', 'secret-password');
    await typeIntoKey(tester, 'delete-confirm-name', 'Al-Fares Electronics');
    expect(deleteEnabled(tester), isFalse);
    await typeIntoKey(tester, 'delete-code', '123456');
    expect(deleteEnabled(tester), isTrue);
  });

  testWidgets('a member deletes only their login and lands on the sign-in screen', (tester) async {
    final server = workspaceServer(account: accountJson(role: 'collector'), routes: {
      'POST /account/deletion': always(json(200, {'data': {'scope': 'login'}})),
      'POST /auth/logout': always(json(200, <String, dynamic>{})),
    });
    await pumpApp(tester, server);
    await openDelete(tester);

    expect(find.text('Delete your login'), findsOneWidget);
    expect(find.byKey(const ValueKey('delete-confirm-name')), findsNothing);
    await typeIntoKey(tester, 'delete-password', 'secret-password');
    await tester.tap(find.byKey(const ValueKey('delete-confirm')));
    await settle(tester);

    expect(jsonDecode(server.requestsTo('POST /account/deletion').single.data as String), {'password': 'secret-password'});
    expect(find.text('Sign in'), findsWidgets);
  });

  testWidgets('while the business is being deleted the dashboard says so, and the owner can restore it', (tester) async {
    final server = workspaceServer(
      account: withTenant(accountJson(), {'deletion_scheduled_for': '2026-11-10'}),
      routes: {'DELETE /account/deletion': always(json(200, {'data': {'restored': true}}))},
    );
    await pumpApp(tester, server);

    expect(find.byKey(const ValueKey('deletion-banner')), findsOneWidget);
    expect(find.textContaining('2026-11-10'), findsOneWidget);

    server.on('GET /me', always(json(200, {'data': accountJson()})));
    await tester.tap(find.byKey(const ValueKey('deletion-restore')));
    await settle(tester);

    expect(server.calls('DELETE /account/deletion'), 1);
    expect(find.byKey(const ValueKey('deletion-banner')), findsNothing);
  });

  testWidgets('a member sees the notice without the way back', (tester) async {
    await pumpApp(tester, workspaceServer(account: withTenant(accountJson(role: 'manager'), {'deletion_scheduled_for': '2026-11-10'})));

    expect(find.byKey(const ValueKey('deletion-banner')), findsOneWidget);
    expect(find.byKey(const ValueKey('deletion-restore')), findsNothing);
  });
}
