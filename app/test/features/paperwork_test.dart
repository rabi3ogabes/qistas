import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/data/appearance.dart' show openExternalProvider;
import 'package:qistas/features/contracts/paperwork_section.dart';
import 'package:qistas/features/documents/document_options_sheet.dart';
import 'package:qistas/features/settings/signature_pad.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan K5 (CT21, CT22): up to three guarantors on a contract, their ID number masked and never shown to a
/// collector, and photos of the ID, the signed contract and the invoice. An ID photo is never shown in the list: it is
/// opened one at a time, through its own short-lived link.

/// An account whose platform has guarantors and photos switched on.
Map<String, dynamic> paperworkAccount({String role = 'owner'}) {
  final account = accountJson(role: role);
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    'guarantors': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
  };

  return account;
}

Map<String, dynamic> guarantorJson({String id = 'g1', String? nationalId = '••••••432'}) => {
      'id': id, 'name': 'Khalid Al-Harbi', 'phone': '0501234567', 'relation': 'Brother', 'national_id': nationalId, 'created_at': '2026-10-12T09:00:00Z',
    };

Map<String, dynamic> photoJson({String id = 'f1', String kind = 'invoice', String label = 'Invoice'}) => {
      'id': id, 'kind': kind, 'label': label, 'mime': 'image/jpeg', 'size': 1200, 'private': kind.startsWith('id_'),
      'url': kind.startsWith('id_') ? null : 'https://files.test/$id', 'created_at': '2026-10-12T09:00:00Z',
    };

Map<String, dynamic> signatureJson({String method = 'screen'}) => {
      'name': 'Ahmad Salem', 'method': method, 'signed_at': '2026-10-12T10:00:00Z', 'image': 'data:image/png;base64,${base64Encode(onePixel)}',
    };

Map<String, dynamic> contractWithPaperwork({List<Map<String, dynamic>> guarantors = const [], List<Map<String, dynamic>> photos = const []}) =>
    {...contractJson(), 'guarantors': guarantors, 'photos': photos};

/// A one-pixel PNG, for the pictures the tests show and send.
final Uint8List onePixel = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

/// What the camera and the gallery were asked for, the links opened outside the app, and the documents shared.
class FakeCamera {
  final List<ImageSource> asked = [];
  final List<Uri> opened = [];
  final List<String> shared = [];

  Future<({Uint8List bytes, String name})?> pick(ImageSource source) async {
    asked.add(source);

    return (bytes: onePixel, name: 'IMG_0001.jpg');
  }
}

Future<FakeCamera> pumpPaperwork(WidgetTester tester, FakeServer server) async {
  final camera = FakeCamera();
  final folder = Directory.systemTemp.createTempSync('qistas-paperwork');
  addTearDown(() => folder.deleteSync(recursive: true));
  await pumpApp(tester, server, overrides: [
    photoPickerProvider.overrideWithValue(camera.pick),
    photoImageProvider.overrideWithValue((_) => MemoryImage(onePixel)),
    openExternalProvider.overrideWithValue((uri) async {
      camera.opened.add(uri);
      return true;
    }),
    documentSharerProvider.overrideWithValue((path, subject) async => camera.shared.add(path.split(Platform.pathSeparator).last)),
    documentFolderProvider.overrideWithValue(() async => folder),
  ]);
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'C-0007');

  return camera;
}

Future<void> scrollToPaperwork(WidgetTester tester) async {
  await tester.scrollUntilVisible(find.text('Paperwork'), 300, scrollable: find.byType(Scrollable).first);
  await tester.pump();
}

void main() {
  testWidgets('adds a guarantor with an ID number', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()})),
      'POST /contracts/k1/guarantors': always(json(201, {'data': guarantorJson()})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    await tapButton(tester, 'Add a guarantor');
    await typeInto(tester, 'Name', 'Khalid Al-Harbi');
    await typeInto(tester, 'Phone', '0501234567');
    await typeInto(tester, 'How they know the customer (optional)', 'Brother');
    await typeInto(tester, 'ID number (optional)', '1098765432');
    await tapButton(tester, 'Add the guarantor');

    final body = jsonDecode(server.requestsTo('POST /contracts/k1/guarantors').single.data as String) as Map<String, dynamic>;
    expect(body, {'name': 'Khalid Al-Harbi', 'phone': '0501234567', 'relation': 'Brother', 'national_id': '1098765432'});
  });

  testWidgets('shows a guarantor with the ID number masked, and changes one without retyping the ID', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork(guarantors: [guarantorJson()])})),
      'PUT /guarantors/g1': always(json(200, {'data': guarantorJson()})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.text('Khalid Al-Harbi'), findsOneWidget);
    expect(find.text('••••••432'), findsOneWidget);

    await tapText(tester, 'Khalid Al-Harbi');
    await typeInto(tester, 'How they know the customer (optional)', 'Cousin');
    await tapButton(tester, 'Save');

    final body = jsonDecode(server.requestsTo('PUT /guarantors/g1').single.data as String) as Map<String, dynamic>;
    expect(body['relation'], 'Cousin');
    expect(body['national_id'], isNull);
    expect(body.containsKey('remove_national_id'), isFalse);
  });

  testWidgets('removes a guarantor once the owner confirms', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork(guarantors: [guarantorJson()])})),
      'DELETE /guarantors/g1': always(const FakeResponse(204, '')),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    await tapTooltip(tester, 'Remove Khalid Al-Harbi');
    await tapText(tester, 'Cancel');
    expect(server.requestsTo('DELETE /guarantors/g1'), isEmpty);

    await tapTooltip(tester, 'Remove Khalid Al-Harbi');
    await tapText(tester, 'Remove', last: true);

    expect(server.requestsTo('DELETE /guarantors/g1'), hasLength(1));
  });

  testWidgets('shows a collector the guarantor’s phone and nothing to change', (tester) async {
    final server = workspaceServer(account: paperworkAccount(role: 'collector'), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork(guarantors: [guarantorJson(nationalId: null)], photos: [photoJson()])})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.text('Khalid Al-Harbi'), findsOneWidget);
    expect(find.text('0501234567'), findsOneWidget);
    expect(find.text('Add a guarantor'), findsNothing);
    expect(find.text('Add a photo'), findsNothing);
    expect(find.byTooltip('Remove Khalid Al-Harbi'), findsNothing);
  });

  testWidgets('keeps a photo of the invoice taken with the camera', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()})),
      'POST /contracts/k1/photos': always(json(201, {'data': photoJson()})),
    });
    final camera = await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    await tapButton(tester, 'Add a photo');
    await tapText(tester, 'Invoice');
    await tapButton(tester, 'Take a photo');

    expect(camera.asked, [ImageSource.camera]);
    final form = server.requestsTo('POST /contracts/k1/photos').single.data as FormData;
    expect([for (final field in form.fields) '${field.key}=${field.value}'], ['kind=invoice']);
    expect(form.files.single.key, 'file');
  });

  testWidgets('shows an ID photo as a private tile and opens it through its own link', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork(photos: [photoJson(), photoJson(id: 'f2', kind: 'id_front', label: 'ID, front')])})),
      'GET /files/f2': always(json(200, {'data': {'url': 'https://files.test/f2?signature=x', 'expires_at': '2026-10-12T09:05:00Z', 'mime': 'image/jpeg'}})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.text('ID, front'), findsOneWidget);
    expect(server.requestsTo('GET /files/f2'), isEmpty);

    await tapText(tester, 'ID, front');

    expect(server.requestsTo('GET /files/f2'), hasLength(1));
    expect(find.byType(InteractiveViewer), findsOneWidget);
  });

  testWidgets('offers nothing new while the switch is off, and still shows what is there', (tester) async {
    final server = workspaceServer(routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork(guarantors: [guarantorJson()])})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.text('Khalid Al-Harbi'), findsOneWidget);
    expect(find.text('Add a guarantor'), findsNothing);
    expect(find.text('Add a photo'), findsNothing);
  });

  testWidgets('shows no paperwork at all on a contract without any while the switch is off', (tester) async {
    await pumpPaperwork(tester, workspaceServer());
    await tester.scrollUntilVisible(find.text('Payments').last, 300, scrollable: find.byType(Scrollable).first);
    await tester.drag(find.byType(Scrollable).first, const Offset(0, -4000));
    await settle(tester);

    expect(find.text('Paperwork'), findsNothing);
  });

  testWidgets('takes the customer’s signature on this phone', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()})),
      'POST /contracts/k1/signature': always(json(201, {'data': signatureJson()})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    await tapButton(tester, 'Sign on this phone');
    await tester.drag(find.byType(SignaturePad), const Offset(120, 30));
    await tester.pump();
    // Turning the strokes into a PNG takes real time, not the test's fake clock.
    await tester.tap(find.widgetWithText(QButton, 'Keep the signature'));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 300)));
    await settle(tester);

    final upload = server.requestsTo('POST /contracts/k1/signature').single.data as FormData;
    final bytes = (await tester.runAsync(() => upload.files.single.value.clone().finalize().expand((chunk) => chunk).toList()))!;
    expect(upload.files.single.key, 'file');
    expect(bytes.take(4).toList(), [0x89, 0x50, 0x4E, 0x47]);
  });

  testWidgets('shows when and how the contract was signed', (tester) async {
    final server = workspaceServer(account: paperworkAccount(role: 'collector'), routes: {
      'GET /contracts/k1': always(json(200, {'data': {...contractWithPaperwork(), 'signature': signatureJson(method: 'link')}})),
    });
    await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.textContaining('Signed on their own phone on'), findsOneWidget);
    expect(find.text('Not signed yet.'), findsNothing);
    expect(find.text('Sign on this phone'), findsNothing);
  });

  testWidgets('sends a signing link: the link on WhatsApp and the code by text message, apart', (tester) async {
    final server = workspaceServer(account: paperworkAccount(), routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()})),
      'POST /contracts/k1/signature-links': always(json(201, {'data': {'url': 'https://qistas.test/sign/abc', 'code': '482913', 'expires_at': '2026-10-13T10:00:00Z', 'phone': '0501234567'}})),
    });
    final camera = await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    await tapButton(tester, 'Send a link to sign on their phone');
    expect(find.text('482913'), findsOneWidget);

    await tapButton(tester, 'Send the link on WhatsApp');
    await tapButton(tester, 'Send the code by text message');

    final whatsapp = camera.opened.first;
    expect(whatsapp.host, 'wa.me');
    expect(whatsapp.path, '/966501234567');
    expect(whatsapp.queryParameters['text'], contains('https://qistas.test/sign/abc'));
    expect(whatsapp.queryParameters['text'], isNot(contains('482913')));
    final sms = camera.opened.last;
    expect(sms.scheme, 'sms');
    expect(sms.toString(), contains('482913'));
    expect(sms.toString(), isNot(contains('qistas.test')));
  });

  testWidgets('prints the contract, and offers the promissory note only while its switch is on', (tester) async {
    final account = paperworkAccount();
    final server = workspaceServer(account: account, routes: {
      'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()})),
      'GET /settings/documents': always(json(200, {'data': {'paper': 'a4', 'text': 'normal', 'sections': {'signature': true}, 'wording': <String, String>{}}})),
      'GET /contracts/k1/agreement.pdf': always(const FakeResponse(200, '%PDF-1.7 a contract')),
    });
    final camera = await pumpPaperwork(tester, server);
    await scrollToPaperwork(tester);

    expect(find.text('Promissory note'), findsNothing);
    await tapButton(tester, 'Contract');
    await tapButton(tester, 'Share');

    expect(server.requestsTo('GET /contracts/k1/agreement.pdf'), hasLength(1));
    expect(camera.shared, ['contract-C-0007.pdf']);
  });

  testWidgets('offers the promissory note to those who add paperwork once its switch is on', (tester) async {
    final account = paperworkAccount();
    account['entitlements'] = {
      ...Map<String, dynamic>.from(account['entitlements'] as Map),
      'promissory_note': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
    };
    await pumpPaperwork(tester, workspaceServer(account: account, routes: {'GET /contracts/k1': always(json(200, {'data': contractWithPaperwork()}))}));
    await scrollToPaperwork(tester);

    expect(find.widgetWithText(QButton, 'Promissory note'), findsOneWidget);
  });
}
