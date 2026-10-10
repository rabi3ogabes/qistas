import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/data/appearance.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The team (Win Plan PP11): who works in the business, invitations by link, roles and removing people.

Map<String, dynamic> teamJson({bool canManage = true, int? limit = 3, int used = 2, List<String>? roles}) => {
      'members': [
        {'id': 'u1', 'name': 'Layla Haddad', 'email': 'layla@example.com', 'role': 'owner', 'joined_at': '2026-10-01T09:00:00+00:00'},
        {'id': 'u2', 'name': 'Omar Khalil', 'email': 'omar@example.com', 'role': 'collector', 'joined_at': '2026-10-05T09:00:00+00:00'},
      ],
      'invitations': [
        if (used > 2) {'id': 'i1', 'role': 'viewer', 'name': 'Sara', 'email': null, 'phone': null, 'invited_by': 'Layla Haddad', 'expires_at': '2026-10-18T10:00:00+00:00'},
      ],
      'can_manage': canManage,
      'assignable_roles': roles ?? (canManage ? ['manager', 'accountant', 'collector', 'viewer'] : <String>[]),
      'limit': limit,
      'used': used,
    };

Future<void> openTeam(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-team')));
  await tester.tap(find.byKey(const ValueKey('settings-team')));
  await settle(tester);
}

void main() {
  testWidgets('lists the people and the invitations waiting, with how many the plan allows', (tester) async {
    await pumpApp(tester, workspaceServer(routes: {'GET /team': always(json(200, {'data': teamJson(used: 3)}))}));
    await openTeam(tester);

    expect(find.text('3 of 3 people on your plan'), findsOneWidget);
    expect(find.textContaining('Layla Haddad'), findsWidgets);
    expect(find.text('Omar Khalil'), findsOneWidget);
    expect(find.text('Collector'), findsOneWidget);
    expect(find.text('Sara'), findsOneWidget);
    expect(find.byKey(const ValueKey('team-invite')), findsOneWidget);
  });

  testWidgets('makes an invitation link for the chosen role and sends it on WhatsApp', (tester) async {
    final opened = <Uri>[];
    final server = workspaceServer(routes: {
      'GET /team': always(json(200, {'data': teamJson()})),
      'POST /team/invitations': always(json(201, {
        'data': {
          'invitation': {'id': 'i9', 'role': 'accountant', 'name': 'Huda', 'expires_at': '2026-10-18T10:00:00+00:00'},
          'url': 'https://qistas.test/invite/abcdefabcdefabcdefabcdefabcdefabcdef',
        },
      })),
    });
    await pumpApp(tester, server, overrides: [
      openExternalProvider.overrideWithValue((uri) async {
        opened.add(uri);
        return true;
      }),
    ]);
    await openTeam(tester);

    await tester.tap(find.byKey(const ValueKey('team-invite')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('invite-role-accountant')));
    await tester.pump();
    await typeInto(tester, 'Their name (optional)', 'Huda');
    await tester.tap(find.byKey(const ValueKey('invite-make')));
    await settle(tester);

    expect(jsonDecode(server.requestsTo('POST /team/invitations').single.data as String), {'role': 'accountant', 'name': 'Huda'});
    expect(find.text('https://qistas.test/invite/abcdefabcdefabcdefabcdefabcdefabcdef'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('invite-whatsapp')));
    await settle(tester);
    expect(opened.single.host, 'wa.me');
    expect(opened.single.queryParameters['text'], contains('https://qistas.test/invite/abcdefabcdefabcdefabcdefabcdefabcdef'));
  });

  testWidgets('a full plan shows the way to upgrade instead of the invitation form', (tester) async {
    await pumpApp(tester, workspaceServer(routes: {'GET /team': always(json(200, {'data': teamJson(limit: 2, used: 2)}))}));
    await openTeam(tester);

    await tester.tap(find.byKey(const ValueKey('team-invite')));
    await settle(tester);

    expect(find.byKey(const ValueKey('invite-make')), findsNothing);
    expect(find.textContaining('Your plan has room for 2 people'), findsOneWidget);
  });

  testWidgets('the owner changes a role and removes someone, after asking', (tester) async {
    final server = workspaceServer(routes: {
      'GET /team': always(json(200, {'data': teamJson()})),
      'PUT /team/members/u2': always(json(200, {'data': {'id': 'u2', 'name': 'Omar Khalil', 'email': 'omar@example.com', 'role': 'manager'}})),
      'DELETE /team/members/u2': always(FakeResponse(204, '')),
    });
    await pumpApp(tester, server);
    await openTeam(tester);

    await tester.tap(find.byKey(const ValueKey('member-u2')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('role-manager')));
    await settle(tester);
    expect(jsonDecode(server.requestsTo('PUT /team/members/u2').single.data as String), {'role': 'manager'});

    await tester.tap(find.byKey(const ValueKey('member-u2')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('member-remove')));
    await settle(tester);
    expect(server.calls('DELETE /team/members/u2'), 0, reason: 'asks first');
    await tester.tap(find.byKey(const ValueKey('member-remove-confirm')));
    await settle(tester);
    expect(server.calls('DELETE /team/members/u2'), 1);
  });

  testWidgets('the owner’s own row and the owner role cannot be touched', (tester) async {
    await pumpApp(tester, workspaceServer(routes: {'GET /team': always(json(200, {'data': teamJson()}))}));
    await openTeam(tester);

    await tester.tap(find.byKey(const ValueKey('member-u1')));
    await settle(tester);

    expect(find.byKey(const ValueKey('member-remove')), findsNothing);
  });

  testWidgets('a collector can look but not invite or change anyone', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountJson(role: 'collector'), routes: {'GET /team': always(json(200, {'data': teamJson(canManage: false)}))}));
    await openTeam(tester);

    expect(find.byKey(const ValueKey('team-invite')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('member-u2')));
    await settle(tester);
    expect(find.byKey(const ValueKey('member-remove')), findsNothing);
  });

  testWidgets('stays out of Settings while the platform has the team switched off', (tester) async {
    final account = accountJson();
    account['entitlements'] = {
      ...Map<String, dynamic>.from(account['entitlements'] as Map),
      'members': {'type': 'limit', 'status': 'platform_off', 'detail': null, 'enabled': false, 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
    };
    await pumpApp(tester, workspaceServer(account: account));
    await openSettings(tester);

    expect(find.byKey(const ValueKey('settings-team')), findsNothing);
  });
}
