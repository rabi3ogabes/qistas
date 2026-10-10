import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/features/documents/document_options_sheet.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP10 on the phone: when the books were last copied, everything in one Excel file through the share sheet,
/// and the activity log for owners and managers.

Map<String, dynamic> exportJson({String id = 'e1', String kind = 'manual', String status = 'ready'}) => {
      'id': id, 'kind': kind, 'format': 'xlsx', 'status': status, 'size': 48213,
      'row_counts': {'customers': 12, 'contracts': 9, 'payments': 31}, 'created_at': '2026-10-11T02:00:00+00:00', 'expires_at': null,
    };

Map<String, dynamic> backupsJson() => {
      'data': {'last_backup_at': '2026-10-11T02:00:00+00:00', 'copies': [exportJson(id: 'n1', kind: 'nightly')]},
      'meta': {'can_export': true, 'kept': 7},
    };

Map<String, dynamic> activityJson() => {
      'data': [
        {'id': 'a1', 'at': '2026-10-11T12:30:00+03:00', 'action': 'payment.recorded', 'kind': 'payments', 'summary': 'Payment recorded: SAR 275.00 on C-0007', 'person': {'id': 'u2', 'name': 'Omar Collector'}},
        {'id': 'a2', 'at': '2026-10-11T09:00:00+03:00', 'action': 'customer.created', 'kind': 'customers', 'summary': 'Customer added: Ahmad Salem', 'person': {'id': 'u1', 'name': 'Layla Haddad'}},
      ],
      'meta': {'current_page': 1, 'last_page': 1, 'total': 2, 'people': [{'id': 'u1', 'name': 'Layla Haddad'}, {'id': 'u2', 'name': 'Omar Collector'}], 'kinds': ['customers', 'payments']},
    };

Future<void> openRow(WidgetTester tester, String key) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(ValueKey(key)));
  await tester.pump();
  await tester.tap(find.byKey(ValueKey(key)));
  await settle(tester);
}

void main() {
  testWidgets('shows the last copy and hands everything to the share sheet as one Excel file', (tester) async {
    final server = workspaceServer(routes: {
      'GET /backups': always(json(200, backupsJson())),
      'POST /exports': always(json(202, {'data': exportJson()})),
      'GET /exports/e1/download': always(json(200, {'data': {'url': 'https://qistas.test/exports/e1/file?signature=abc', 'expires_at': '2026-10-11T09:05:00+00:00', 'filename': 'qistas-2026-10-11.xlsx'}})),
      'GET /exports/e1/file': always(const FakeResponse(200, 'PK fake workbook')),
    });
    final shared = <String>[];
    final folder = Directory.systemTemp.createTempSync('qistas-backups');
    addTearDown(() => folder.deleteSync(recursive: true));
    await pumpApp(tester, server, overrides: [
      documentSharerProvider.overrideWithValue((path, subject) async => shared.add(path.split(Platform.pathSeparator).last)),
      documentFolderProvider.overrideWithValue(() async => folder),
    ]);

    await openRow(tester, 'settings-backups');
    expect(find.textContaining('Oct 11'), findsWidgets);

    await tapButton(tester, 'Excel file');

    expect(server.calls('POST /exports'), 1);
    expect(server.adapter.requests.firstWhere((r) => r.method == 'POST').data, contains('"format":"xlsx"'));
    expect(shared, ['qistas-2026-10-11.xlsx']);
  });

  testWidgets('lists what the team did, and filters by person', (tester) async {
    final server = workspaceServer(routes: {'GET /activity': always(json(200, activityJson()))});
    await pumpApp(tester, server);

    await openRow(tester, 'settings-activity');
    expect(find.text('Payment recorded: SAR 275.00 on C-0007'), findsOneWidget);
    expect(find.textContaining('Omar Collector'), findsWidgets);

    await tapText(tester, 'Everyone');
    await tapText(tester, 'Omar Collector', last: true);

    expect(server.requestsTo('GET /activity').last.queryParameters['user'], 'u2');
  });

  testWidgets('offers backups and the activity log only to those allowed', (tester) async {
    final server = workspaceServer(account: accountJson(role: 'collector'));
    await pumpApp(tester, server);
    await openSettings(tester);

    expect(find.byKey(const ValueKey('settings-backups')), findsNothing);
    expect(find.byKey(const ValueKey('settings-activity')), findsNothing);
  });
}
