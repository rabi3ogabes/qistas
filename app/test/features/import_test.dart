import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/features/billing/plans_screen.dart';
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

/// What a workspace may bring in: with the key saved and the plan including it, photos and PDFs are read.
Map<String, dynamic> optionsJson({bool available = true, bool included = true, int? pagesLeft = 50}) => {
      'data': {'rows_limit': 100, 'ai': {'available': available, 'included': included, 'pages_left': pagesLeft}},
    };

/// What the pickers handed over, and the documents shared.
class FakeFiles {
  final List<ImportPick> picked = [];
  final List<String> shared = [];

  Future<({Uint8List bytes, String name})?> pick(ImportPick kind) async {
    picked.add(kind);

    return switch (kind) {
      ImportPick.sheet => (bytes: Uint8List.fromList(utf8.encode('name,phone\nAhmad,0501234567\n')), name: 'customers.csv'),
      ImportPick.camera => (bytes: Uint8List.fromList(const [0xFF, 0xD8, 0xFF, 0xE0]), name: 'notebook.jpg'),
      ImportPick.photoOrPdf => (bytes: Uint8List.fromList(utf8.encode('%PDF-1.7')), name: 'report.pdf'),
    };
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

    expect(files.picked, [ImportPick.sheet]);
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

  testWidgets('reads a photo of the notebook once the key is saved, saying it takes a moment', (tester) async {
    final answer = Completer<FakeResponse>();
    final server = workspaceServer(account: importAccount(), routes: {
      'GET /imports/options': always(json(200, optionsJson())),
      'POST /imports': (_) => answer.future,
    });
    final files = await pumpImport(tester, server);
    await openImport(tester);
    await tester.scrollUntilVisible(find.text('Pages left this month: 50'), 200, scrollable: find.byType(Scrollable).first);

    // The answer is held back: the page is still being read.
    await tapButton(tester, 'Take a photo of the notebook');

    expect(files.picked, [ImportPick.camera]);
    final request = server.requestsTo('POST /imports').single;
    expect((request.data as FormData).files.single.value.filename, 'notebook.jpg');
    // A page can take Claude most of a minute; an Excel file's 30 seconds would give up on it.
    expect(request.receiveTimeout, const Duration(seconds: 150));
    expect(find.text('Reading the page. This can take up to a minute.'), findsOneWidget);

    answer.complete(json(201, {'data': {...importJson(), 'source': 'ai', 'filename': 'notebook.jpg'}}));
    await settle(tester);
    expect(find.text('Ahmad Salem'), findsOneWidget);
  });

  testWidgets('reads a PDF report, and says so when the month’s pages are used up', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {
      'GET /imports/options': always(json(200, optionsJson(pagesLeft: 1))),
      'POST /imports': always(apiError(402, 'limit_reached', 'The pages read this month are used up.')),
    });
    final files = await pumpImport(tester, server);
    await openImport(tester);

    await tapButton(tester, 'Choose a photo or PDF');

    expect(files.picked, [ImportPick.photoOrPdf]);
    expect((server.requestsTo('POST /imports').single.data as FormData).files.single.value.filename, 'report.pdf');
    expect(find.text('The pages read this month are used up.'), findsOneWidget);
  });

  testWidgets('says reading photos is part of Pro when the plan does not have it', (tester) async {
    final server = workspaceServer(account: importAccount(), routes: {'GET /imports/options': always(json(200, optionsJson(included: false, pagesLeft: 0)))});
    await pumpImport(tester, server);
    await openImport(tester);

    expect(find.widgetWithText(QButton, 'Take a photo of the notebook'), findsNothing);
    expect(find.text('Pages left this month: 0'), findsNothing);
    await tester.scrollUntilVisible(find.text('Part of Pro: a photo of a notebook page, or the PDF reports from your old app, read for you into the same rows.'), 200, scrollable: find.byType(Scrollable).first);

    await tapButton(tester, 'See Pro');
    expect(find.byType(PlansScreen), findsOneWidget);
  });

  testWidgets('does not mention photos before the key is saved, or when the options cannot be had', (tester) async {
    for (final options in [always(json(200, optionsJson(available: false))), null]) {
      final server = workspaceServer(account: importAccount(), routes: {'GET /imports/options': ?options});
      await pumpImport(tester, server);
      await openImport(tester);

      expect(find.widgetWithText(QButton, 'Choose a file'), findsOneWidget);
      expect(find.widgetWithText(QButton, 'Take a photo of the notebook'), findsNothing);
      expect(find.widgetWithText(QButton, 'Choose a photo or PDF'), findsNothing);
      expect(find.textContaining('Part of Pro'), findsNothing);
      await tester.pumpWidget(const SizedBox());
    }
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
