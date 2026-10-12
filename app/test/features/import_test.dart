import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/features/documents/document_options_sheet.dart';
import 'package:qistas/features/import/import_screen.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan K9 (RP17): a shop brings its customers in from an Excel or CSV file. The trial run shows every row and every
/// problem by row and column, the columns can be chosen again, and nothing is saved until the owner confirms.

Map<String, dynamic> importAccount({String role = 'owner', bool on = true}) {
  final account = accountJson(role: role);
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    if (on) 'import': {'type': 'limit', 'enabled': true, 'status': 'on', 'limit': 100, 'used': 0, 'remaining': 100, 'unlimited': false},
  };

  return account;
}

Map<String, dynamic> importJson({String status = 'trial', String? owesUs}) => {
      'id': 'i1', 'status': status, 'source': 'xlsx', 'filename': 'customers.xlsx',
      'columns': [
        {'index': 0, 'header': 'الاسم', 'field': 'name'},
        {'index': 1, 'header': 'Mobile no.', 'field': 'phone'},
        {'index': 2, 'header': 'Owes us', 'field': owesUs},
      ],
      'fields': [
        {'key': 'name', 'label': 'Customer’s name'},
        {'key': 'phone', 'label': 'Phone'},
        {'key': 'balance', 'label': 'Still owed'},
      ],
      'rows': [
        {
          'row': 2, 'name': 'Ahmad Salem', 'phone': '0501234567', 'national_id': null, 'customer_id': null, 'action': 'new',
          'contract': {'type': 'scheduled', 'total': '1200.00', 'paid': '300.00', 'balance': '900.00', 'installments': 4, 'first_due': '2026-11-01', 'start': '2026-10-12', 'item': null},
          'problems': <Object?>[], 'note': null,
        },
        {
          'row': 3, 'name': null, 'phone': '0502222222', 'national_id': null, 'customer_id': null, 'action': 'new', 'contract': null,
          'problems': [{'column': 'الاسم', 'field': 'name', 'message': 'Add the customer’s name.'}], 'note': null,
        },
      ],
      'summary': {'rows': 2, 'ready': 1, 'problems': 1, 'skipped': 1, 'customers': 1, 'contracts': 1, 'balance': '900.00'},
      'created_at': '2026-10-12T10:00:00Z', 'confirmed_at': status == 'done' ? '2026-10-12T10:05:00Z' : null,
    };

/// What the file picker handed over, and the documents shared.
class FakeFiles {
  int picked = 0;
  final List<String> shared = [];

  Future<({Uint8List bytes, String name})?> pick() async {
    picked++;

    return (bytes: Uint8List.fromList(utf8.encode('name,phone\nAhmad,0501234567\n')), name: 'customers.csv');
  }
}

Future<FakeFiles> pumpImport(WidgetTester tester, FakeServer server) async {
  final files = FakeFiles();
  final folder = Directory.systemTemp.createTempSync('qistas-import');
  addTearDown(() => folder.deleteSync(recursive: true));
  await pumpApp(tester, server, overrides: [
    importFilePickerProvider.overrideWithValue(files.pick),
    documentSharerProvider.overrideWithValue((path, subject) async => files.shared.add(path.split(Platform.pathSeparator).last)),
    documentFolderProvider.overrideWithValue(() async => folder),
  ]);

  return files;
}

Future<void> openImport(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-import')));
  await tester.pump();
  await tester.tap(find.byKey(const ValueKey('settings-import')));
  await settle(tester);
}

void main() {
  testWidgets('reads a file and shows the trial run, every problem by row and column', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {'POST /imports': always(json(201, {'data': importJson()}))});
    final files = await pumpImport(tester, server);
    await openImport(tester);

    await tapButton(tester, 'Choose a file');

    expect(files.picked, 1);
    final form = server.requestsTo('POST /imports').single.data as FormData;
    expect(form.files.single.key, 'file');
    expect(form.files.single.value.filename, 'customers.csv');
    expect(find.text('Ahmad Salem'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('الاسم: Add the customer’s name.'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('الاسم: Add the customer’s name.'), findsOneWidget);
    expect(find.widgetWithText(QButton, 'Bring in the rows that are ready: 1'), findsOneWidget);
  });

  testWidgets('chooses a column the file named its own way', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {
      'POST /imports': always(json(201, {'data': importJson()})),
      'PUT /imports/i1': always(json(200, {'data': importJson(owesUs: 'balance')})),
    });
    await pumpImport(tester, server);
    await openImport(tester);
    await tapButton(tester, 'Choose a file');

    await tapText(tester, 'Leave out');
    await tapText(tester, 'Still owed', last: true);

    final body = jsonDecode(server.requestsTo('PUT /imports/i1').single.data as String) as Map<String, dynamic>;
    expect(body, {'mapping': {'2': 'balance'}});
  });

  testWidgets('brings the ready rows in once confirmed, and says what was done', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {
      'POST /imports': always(json(201, {'data': importJson()})),
      'POST /imports/i1/confirm': always(json(200, {'data': importJson(status: 'done')})),
    });
    await pumpImport(tester, server);
    await openImport(tester);
    await tapButton(tester, 'Choose a file');

    await tapButton(tester, 'Bring in the rows that are ready: 1');

    expect(server.requestsTo('POST /imports/i1/confirm'), hasLength(1));
    expect(find.text('Brought in. New customers: 1. Contracts: 1.'), findsOneWidget);
    expect(find.widgetWithText(QButton, 'Bring in the rows that are ready: 1'), findsNothing);
  });

  testWidgets('shares the template with the column names', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {
      'GET /imports/template.xlsx': always(const FakeResponse(200, 'PK\u0003\u0004 a sheet')),
    });
    final files = await pumpImport(tester, server);
    await openImport(tester);

    await tapButton(tester, 'Get the template');

    expect(server.requestsTo('GET /imports/template.xlsx').single.queryParameters['language'], 'en');
    expect(files.shared, ['qistas-customers.xlsx']);
  });

  testWidgets('is only for owners and managers, with the switch on', (tester) async {
    for (final (account, shown) in [(importAccount(role: 'collector'), false), (importAccount(on: false), false), (importAccount(role: 'manager'), true)]) {
      await pumpImport(tester, workspaceServer(account: account));
      await openSettings(tester);

      expect(find.byKey(const ValueKey('settings-import')), shown ? findsOneWidget : findsNothing);
      await tester.pumpWidget(const SizedBox());
    }
  });
}
