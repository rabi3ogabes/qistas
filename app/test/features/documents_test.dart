import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/features/documents/document_options_sheet.dart';
import 'package:qistas/features/settings/business_profile_screen.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP8: statements, reports and receipts as PDF files sent from the phone through the share sheet (straight to
/// the customer's WhatsApp, an email or a printer), and the business profile they come from, with a signature drawn on
/// the screen.

/// What a PDF looks like to the app: bytes that start with %PDF-.
FakeResponse pdf() => const FakeResponse(200, '%PDF-1.7 a statement');

Map<String, dynamic> profileJson({String? nameEn, String? signature}) => {
      'name_ar': null, 'name_en': nameEn, 'phone': null, 'address': null, 'cr_number': null, 'vat_number': null, 'footer': null,
      'logo': null, 'signature': signature,
    };

Map<String, dynamic> preferencesJson() => {
      'paper': 'a4', 'text': 'normal', 'sections': {'cost': false, 'overdue': true, 'schedule': true, 'signature': true}, 'wording': <String, String>{},
    };

/// What the share sheet was handed: the file's name and its bytes.
class SharedFiles {
  final List<(String, List<int>)> files = [];

  Future<void> share(String path, String subject) async => files.add((path.split(Platform.pathSeparator).last, File(path).readAsBytesSync()));
}

Future<SharedFiles> pumpWithSharing(WidgetTester tester, FakeServer server) async {
  final shared = SharedFiles();
  final folder = Directory.systemTemp.createTempSync('qistas-docs');
  addTearDown(() => folder.deleteSync(recursive: true));
  await pumpApp(tester, server, overrides: [
    documentSharerProvider.overrideWithValue(shared.share),
    documentFolderProvider.overrideWithValue(() async => folder),
  ]);

  return shared;
}

/// Taps whatever [finder] finds first, once it is on screen.
Future<void> tapFirst(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder.first);
  await tester.pump();
  await tester.tap(finder.first);
  await settle(tester);
}

FakeServer documentServer(Map<String, Route> routes) => workspaceServer(routes: {
      'GET /settings/documents': always(json(200, {'data': preferencesJson()})),
      ...routes,
    });

void main() {
  testWidgets('shares a contract statement as a PDF, with the choices made for it', (tester) async {
    final server = documentServer({'GET /contracts/k1/statement.pdf': always(pdf())});
    final shared = await pumpWithSharing(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');

    await tapTooltip(tester, 'Statement');
    await tapText(tester, 'A5');
    await tapText(tester, 'One-page summary');
    await tapButton(tester, 'Share');

    final query = server.requestsTo('GET /contracts/k1/statement.pdf').single.queryParameters;
    expect(query['paper'], 'a5');
    expect(query['summary'], '1');
    expect(query['sections[overdue]'], '1');
    expect(query['language'], 'en');
    expect(shared.files.single.$1, 'statement-C-0007.pdf');
    expect(String.fromCharCodes(shared.files.single.$2.take(5)), '%PDF-');
  });

  testWidgets('offers the cost only to someone who sees the business’s money', (tester) async {
    for (final (role, offered) in [('owner', true), ('collector', false)]) {
      final server = documentServer({'GET /me': always(json(200, {'data': accountJson(role: role)}))});
      await pumpWithSharing(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapTooltip(tester, 'Statement');

      expect(find.text('What it cost you'), offered ? findsOneWidget : findsNothing, reason: role);
      await tester.pumpWidget(const SizedBox());
    }
  });

  testWidgets('says so when the month’s documents are used up, and shares nothing', (tester) async {
    final server = documentServer({
      'GET /contracts/k1/statement.pdf': always(apiError(402, 'limit_reached', 'You have reached the limit of 5 PDF documents on your plan.', extra: {'feature': 'pdf_statements', 'limit': 5, 'used': 5})),
    });
    final shared = await pumpWithSharing(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');

    await tapTooltip(tester, 'Statement');
    await tapButton(tester, 'Share');

    expect(shared.files, isEmpty);
    expect(find.textContaining('5'), findsWidgets);
  });

  testWidgets('sends the receipt as a PDF first thing after a payment', (tester) async {
    final server = documentServer({
      'POST /contracts/k1/payments': always(json(201, {'data': lineJson()})),
      'GET /payments/t1/receipt.pdf': always(pdf()),
    });
    final shared = await pumpWithSharing(tester, server);
    await tapFirst(tester, find.widgetWithText(FilledButton, 'Record payment').last);
    await tapButton(tester, 'Record payment');

    await tapButton(tester, 'Send receipt');
    await tapText(tester, 'Till roll, 80 mm');
    await tapButton(tester, 'Share');

    expect(server.requestsTo('GET /payments/t1/receipt.pdf').single.queryParameters['paper'], '80mm');
    expect(shared.files.single.$1, 'receipt-C-0007.pdf');
  });

  testWidgets('keeps the business profile and saves a signature drawn on the screen as a PNG', (tester) async {
    final server = documentServer({
      'GET /settings/business-profile': always(json(200, {'data': profileJson(), 'meta': {'can_edit': true}})),
      'PUT /settings/business-profile': always(json(200, {'data': profileJson(nameEn: 'Al-Fares Electronics')})),
      'POST /settings/business-profile/signature': always(json(200, {'data': profileJson(signature: 'data:image/png;base64,iVBORw0KGgo=')})),
    });
    await pumpApp(tester, server);
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-business')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-business')));
    await settle(tester);

    await typeInto(tester, 'Name in English', 'Al-Fares Electronics');
    await typeInto(tester, 'VAT number', '300012345600003');
    await tapButton(tester, 'Save the profile');
    final body = server.adapter.requests.firstWhere((r) => r.method == 'PUT').data as String;
    expect(body, contains('"name_en":"Al-Fares Electronics"'));
    expect(body, contains('"vat_number":"300012345600003"'));

    final pad = find.byType(SignaturePad);
    await tester.ensureVisible(pad);
    await tester.pump();
    await tester.drag(pad, const Offset(120, 30));
    await tester.pump();
    // Turning the strokes into a PNG takes real time, not the test's fake clock.
    await tester.tap(find.widgetWithText(QButton, 'Save the signature'));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 300)));
    await settle(tester);

    final upload = server.requestsTo('POST /settings/business-profile/signature').single.data as FormData;
    final bytes = (await tester.runAsync(() => upload.files.single.value.clone().finalize().expand((chunk) => chunk).toList()))!;
    expect(bytes.take(4).toList(), [0x89, 0x50, 0x4E, 0x47]);
  });

  testWidgets('shows a collector the profile without letting them change it', (tester) async {
    final server = documentServer({
      'GET /me': always(json(200, {'data': accountJson(role: 'collector')})),
      'GET /settings/business-profile': always(json(200, {'data': profileJson(nameEn: 'Al-Fares Electronics'), 'meta': {'can_edit': false}})),
    });
    await pumpApp(tester, server);
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-business')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-business')));
    await settle(tester);

    expect(find.text('Al-Fares Electronics'), findsWidgets);
    expect(find.byType(SignaturePad), findsNothing);
    expect(find.text('Save the profile'), findsNothing);
  });
}
