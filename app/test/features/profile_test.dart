import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:qistas/core/design/luxe.dart';
import 'package:qistas/features/contracts/paperwork_section.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Your profile in the app, the same as on the web: your photo, name, e-mail, phone and language, and your password.
/// What the server says is wrong shows under the field it is about; a saved change shows at once.

/// A 1×1 PNG, as a photo the phone gives back.
final Uint8List onePixel = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

Map<String, dynamic> me({String name = 'Layla Haddad', String? phone, String? photo, String locale = 'en', bool verified = true}) {
  final account = accountJson();
  account['user'] = {
    ...Map<String, dynamic>.from(account['user'] as Map),
    'name': name, 'phone': phone, 'photo': photo, 'locale': locale, 'email_verified': verified, 'member_since': '2026-10-08',
  };

  return account;
}

Future<void> openProfile(WidgetTester tester) async {
  await openSettings(tester);
  await tester.tap(find.byKey(const ValueKey('settings-profile')));
  await settle(tester);
}

void main() {
  testWidgets('opens from the top of Settings with who you are and what can be changed', (tester) async {
    await pumpApp(tester, workspaceServer(account: me(phone: '+966501234567')));
    await openProfile(tester);

    expect(find.text('Your profile'), findsWidgets);
    expect(find.text('Layla Haddad'), findsWidgets);
    expect(find.text('layla@example.com'), findsWidgets);
    expect(find.widgetWithText(TextField, '+966501234567'), findsOneWidget);
    expect(find.text('Your details'), findsOneWidget);
    expect(find.text('Password'), findsWidgets);
  });

  testWidgets('saves your details and shows them at once', (tester) async {
    final server = workspaceServer(account: me(), routes: {'PUT /me': always(json(200, {'data': me(name: 'Layla Saleh', phone: '0551112201')}))});
    await pumpApp(tester, server);
    await openProfile(tester);

    await typeIntoKey(tester, 'profile-name', 'Layla Saleh');
    await typeIntoKey(tester, 'profile-phone', '0551112201');
    await tapButton(tester, 'Save changes');

    final sent = jsonDecode(server.requestsTo('PUT /me').single.data as String) as Map<String, dynamic>;
    expect(sent, containsPair('name', 'Layla Saleh'));
    expect(sent, containsPair('phone', '0551112201'));
    expect(sent, containsPair('email', 'layla@example.com'));
    expect(find.text('Your details are saved.'), findsOneWidget);
    // The card at the top, not just the field typed in, says the new name: the whole app has the change.
    expect(find.descendant(of: find.byType(HeroPanel), matching: find.text('Layla Saleh')), findsOneWidget);
  });

  testWidgets('shows what the server says is wrong under the field it is about', (tester) async {
    await pumpApp(tester, workspaceServer(account: me(), routes: {
      'PUT /me': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
        'fields': {'email': ['The email has already been taken.'], 'phone': ['Enter a valid phone number.']},
      })),
    }));
    await openProfile(tester);

    await typeIntoKey(tester, 'profile-email', 'taken@example.com');
    await tapButton(tester, 'Save changes');

    expect(find.text('The email has already been taken.'), findsOneWidget);
    expect(find.text('Enter a valid phone number.'), findsOneWidget);
  });

  testWidgets('changes the password with the current one, and says why when it cannot', (tester) async {
    var calls = 0;
    final server = workspaceServer(account: me(), routes: {
      'PUT /me/password': (_) => ++calls == 1
          ? apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
              'fields': {'current_password': ['The provided password does not match your current password.']},
            })
          : const FakeResponse(204, ''),
    });
    await pumpApp(tester, server);
    await openProfile(tester);

    await typeIntoKey(tester, 'profile-current-password', 'wrong');
    await typeIntoKey(tester, 'profile-new-password', 'N3w!Passw0rd#1');
    await typeIntoKey(tester, 'profile-confirm-password', 'N3w!Passw0rd#1');
    await tapButton(tester, 'Change password');
    expect(find.text('The provided password does not match your current password.'), findsOneWidget);

    await typeIntoKey(tester, 'profile-current-password', 'Old!Passw0rd#1');
    await tapButton(tester, 'Change password');

    final sent = jsonDecode(server.requestsTo('PUT /me/password').last.data as String) as Map<String, dynamic>;
    expect(sent, {'current_password': 'Old!Passw0rd#1', 'password': 'N3w!Passw0rd#1', 'password_confirmation': 'N3w!Passw0rd#1'});
    expect(find.text('Your password is changed.'), findsOneWidget);
  });

  testWidgets('will not send a new password that does not match its confirmation', (tester) async {
    final server = workspaceServer(account: me());
    await pumpApp(tester, server);
    await openProfile(tester);

    await typeIntoKey(tester, 'profile-current-password', 'Old!Passw0rd#1');
    await typeIntoKey(tester, 'profile-new-password', 'N3w!Passw0rd#1');
    await typeIntoKey(tester, 'profile-confirm-password', 'Something else');
    await tapButton(tester, 'Change password');

    expect(find.text('The two new passwords are not the same.'), findsOneWidget);
    expect(server.calls('PUT /me/password'), 0);
  });

  testWidgets('takes a photo from the phone, shows it, and takes it away', (tester) async {
    final photo = 'data:image/png;base64,${base64Encode(onePixel)}';
    final server = workspaceServer(account: me(), routes: {
      'POST /me/photo': always(json(200, {'data': me(photo: photo)})),
      'DELETE /me/photo': always(json(200, {'data': me()})),
    });
    await pumpApp(tester, server, overrides: [
      photoPickerProvider.overrideWithValue((ImageSource source) async => (bytes: onePixel, name: 'me.png')),
    ]);
    await openProfile(tester);

    await tester.tap(find.byKey(const ValueKey('profile-photo-change')));
    await settle(tester);
    await tapText(tester, 'Choose from your photos');

    final upload = server.requestsTo('POST /me/photo').single.data as FormData;
    expect(upload.files.single.key, 'photo');
    expect(find.byKey(const ValueKey('profile-photo-image')), findsOneWidget);

    await tester.ensureVisible(find.byKey(const ValueKey('profile-photo-remove')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('profile-photo-remove')));
    await settle(tester);
    expect(server.calls('DELETE /me/photo'), 1);
    expect(find.byKey(const ValueKey('profile-photo-image')), findsNothing);
  });

  testWidgets('speaks the language chosen for the profile at once', (tester) async {
    await pumpApp(tester, workspaceServer(account: me(), routes: {'PUT /me': always(json(200, {'data': me(locale: 'ar')}))}));
    await openProfile(tester);

    await tester.ensureVisible(find.byKey(const ValueKey('profile-language')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('profile-language')));
    await settle(tester);
    await tapText(tester, 'العربية', last: true);
    await tapButton(tester, 'Save changes');

    expect(find.text('ملفك الشخصي'), findsWidgets);
  });
}
