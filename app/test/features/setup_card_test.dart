import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/features/setup/countries.dart';
import 'package:qistas/features/setup/setup_state.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Go-live basics, the phone's half of the first-run card: the owner of a business that has not been named yet is asked,
/// once, on Today, for the name and (if the server's guess is wrong) the country. Everyone else reads one quiet line, and
/// a server that does not know the card shows nothing.

const String ownerNotice = 'Your business owner needs to name the business before documents can be printed.';
const String nameFirst = 'Name your business first. It is printed on every receipt.';

final Finder card = find.byKey(const ValueKey('setup-card'));
final Finder notice = find.byKey(const ValueKey('setup-notice'));
final Finder saveButton = find.byKey(const ValueKey('setup-save'));

ProviderContainer containerOf(WidgetTester tester) => ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first));

Account accountOf(WidgetTester tester) => containerOf(tester).read(accountProvider)!;

/// What the last save sent.
Map<String, dynamic> bodyOf(FakeServer server) => jsonDecode(server.requestsTo('PUT /workspace/setup').last.data as String) as Map<String, dynamic>;

/// A server whose business has not been named; saving answers with the business as the real server describes it.
FakeServer setupServer({Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => workspaceServer(
      account: account ?? accountWithSetup(),
      routes: {'PUT /workspace/setup': always(json(200, {'data': {'tenant': tenantAfterSetupJson()}})), ...routes},
    );

Map<String, dynamic> words(String language) => jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

/// What a 422 on the setup answers: the server's own sentence, keyed on the field it is about.
FakeResponse refused(String field, String message) => apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
      'fields': {field: [message]},
    });

Future<void> saveNow(WidgetTester tester) async {
  await tester.ensureVisible(saveButton);
  await tester.pump();
  await tester.tap(saveButton);
  await settle(tester);
}

/// Opens the country chooser and picks [name] from its list.
Future<void> pickCountry(WidgetTester tester, String name) async {
  final field = find.byKey(const ValueKey('setup-country-button'));
  await tester.ensureVisible(field);
  await tester.pump();
  await tester.tap(field);
  await settle(tester);
  await tester.tap(find.descendant(of: find.byType(BottomSheet), matching: find.text(name)));
  await settle(tester);
}

void main() {
  group('who sees the card', () {
    testWidgets('asks the owner of an unnamed business for its name, with the placeholder as a hint', (tester) async {
      await pumpApp(tester, setupServer());

      expect(card, findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('Name your business')), findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('It is printed on every receipt and statement.')), findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('Business name')), findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('Business of Layla')), findsOneWidget, reason: 'the placeholder name is the hint');
      final input = tester.widget<EditableText>(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.byType(EditableText)));
      expect(input.controller.text, isEmpty, reason: 'the field starts empty');
      expect(find.descendant(of: card, matching: find.text('Save and continue')), findsOneWidget);
      expect(notice, findsNothing);
    });

    testWidgets('is at the top of Today, above the figures', (tester) async {
      await pumpApp(tester, setupServer());

      expect(tester.getTopLeft(card).dy, lessThan(tester.getTopLeft(find.text('Still to collect')).dy));
    });

    testWidgets('is there even when the figures cannot be loaded', (tester) async {
      await pumpApp(tester, setupServer(routes: {'GET /dashboard': always(apiError(500, 'server_error', 'Server Error'))}));

      expect(card, findsOneWidget);
    });

    for (final role in ['manager', 'accountant', 'collector', 'viewer']) {
      testWidgets('shows a $role no card, only a quiet line in its place', (tester) async {
        final server = setupServer(account: accountWithSetup(role: role));
        await pumpApp(tester, server);

        expect(card, findsNothing);
        expect(find.text('Save and continue'), findsNothing);
        expect(find.descendant(of: notice, matching: find.text(ownerNotice)), findsOneWidget);
        expect(server.adapter.requests.where((r) => r.path.contains('setup')), isEmpty, reason: 'nothing is asked or sent for a $role');
      });
    }

    testWidgets('shows a confirmed owner neither the card nor the line', (tester) async {
      final server = setupServer(account: accountWithSetup(confirmed: true));
      await pumpApp(tester, server);

      expect(card, findsNothing);
      expect(notice, findsNothing);
      expect(find.text(ownerNotice), findsNothing);
      expect(server.adapter.requests.where((r) => r.path.contains('setup')), isEmpty);
    });

    testWidgets('shows nothing when the server does not know the card (it leaves the key out)', (tester) async {
      for (final role in ['owner', 'manager']) {
        final server = setupServer(account: accountWithSetup(role: role, setup: false));
        await pumpApp(tester, server);

        expect(card, findsNothing, reason: role);
        expect(notice, findsNothing, reason: role);
        expect(server.adapter.requests.where((r) => r.path.contains('setup')), isEmpty, reason: role);
        await tester.pumpWidget(const SizedBox());
      }
    });

    testWidgets('shows nothing to a confirmed member either', (tester) async {
      await pumpApp(tester, setupServer(account: accountWithSetup(role: 'manager', confirmed: true)));

      expect(card, findsNothing);
      expect(notice, findsNothing);
    });
  });

  group('the place', () {
    testWidgets('says where the server thinks the business is, with a Change control and no chooser yet', (tester) async {
      await pumpApp(tester, setupServer());

      expect(find.descendant(of: card, matching: find.text('We think you are in Qatar: QAR, Qatar.')), findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('Change')), findsOneWidget);
      expect(find.byKey(const ValueKey('setup-country')), findsNothing);
      expect(find.text('Select your country'), findsNothing);
    });

    testWidgets('names the zone by its city, with underscores as spaces', (tester) async {
      await pumpApp(tester, setupServer(account: accountWithSetup(country: 'MY', currency: 'MYR', timezone: 'Asia/Kuala_Lumpur')));

      expect(find.text('We think you are in Malaysia: MYR, Kuala Lumpur.'), findsOneWidget);
    });

    testWidgets('opens a chooser on Change, with the current country chosen', (tester) async {
      await pumpApp(tester, setupServer());

      await tapText(tester, 'Change');

      final field = find.byKey(const ValueKey('setup-country'));
      expect(field, findsOneWidget);
      expect(find.descendant(of: field, matching: find.text('Qatar')), findsOneWidget);
      expect(find.text('Country'), findsOneWidget);
      expect(find.text('The currency and time zone follow the country.'), findsOneWidget);
    });

    testWidgets('lists exactly the countries a business can start in, each by name', (tester) async {
      await pumpApp(tester, setupServer());
      await tapText(tester, 'Change');

      await tester.tap(find.byKey(const ValueKey('setup-country-button')));
      await settle(tester);

      expect(countryCodes, hasLength(35));
      expect(countryCodes.toSet(), hasLength(35));
      final sheet = find.byType(BottomSheet);
      expect(find.descendant(of: sheet, matching: find.text('Saudi Arabia')), findsOneWidget);
      expect(find.descendant(of: sheet, matching: find.text('Kuwait')), findsOneWidget);
      expect(find.descendant(of: sheet, matching: find.byIcon(Icons.check)), findsOneWidget, reason: 'the current country is marked');
    });

    testWidgets('asks outright, with the chooser at once, when the server could not tell', (tester) async {
      await pumpApp(tester, setupServer(account: accountWithSetup(detected: false)));

      expect(find.text('We could not tell where you are. Choose your country: its currency and time zone follow.'), findsOneWidget);
      expect(find.byKey(const ValueKey('setup-country')), findsOneWidget);
      expect(find.text('Change'), findsNothing);
      expect(find.textContaining('We think you are in'), findsNothing);
    });

    testWidgets('asks for the name only, and says why, when the place can no longer change', (tester) async {
      await pumpApp(tester, setupServer(account: accountWithSetup(canChange: false)));

      expect(find.textContaining('The country can no longer be changed because this business already has contracts or payments.'), findsOneWidget);
      expect(find.byKey(const ValueKey('setup-country')), findsNothing);
      expect(find.text('Change'), findsNothing);
      expect(find.byKey(const ValueKey('setup-name')), findsOneWidget);
      expect(saveButton, findsOneWidget);
    });

    testWidgets('gives a screen reader a Change button that says whether the chooser is open', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, setupServer());

      final change = find.byKey(const ValueKey('setup-change'));
      expect(tester.getSemantics(change), isSemantics(isButton: true, label: 'Change', hasTapAction: true, hasExpandedState: true, isExpanded: false));

      await tapText(tester, 'Change');
      expect(tester.getSemantics(change), isSemantics(isButton: true, label: 'Change', hasExpandedState: true, isExpanded: true));
      handle.dispose();
    });

    testWidgets('keeps a touch target of at least 48 on Change and on the chooser', (tester) async {
      await pumpApp(tester, setupServer());

      expect(tester.getSize(find.byKey(const ValueKey('setup-change'))).height, greaterThanOrEqualTo(48));
      await tapText(tester, 'Change');
      expect(tester.getSize(find.byKey(const ValueKey('setup-country-button'))).height, greaterThanOrEqualTo(48));
      expect(tester.getSize(saveButton).height, greaterThanOrEqualTo(48));
    });
  });

  group('saving', () {
    testWidgets('sends only the name when the place was not touched, then folds the card away', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', '  Al-Fares Electronics ');
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'Al-Fares Electronics'});
      expect(card, findsNothing);
      expect(notice, findsNothing);
      expect(accountOf(tester).businessName, 'Al-Fares Electronics');
      expect(accountOf(tester).setup!.confirmed, isTrue);
      expect(find.text('Still to collect'), findsOneWidget, reason: 'Today goes on as it was');
    });

    testWidgets('sends the name and the country when the person changed it, and never a time zone', (tester) async {
      final server = setupServer(routes: {
        'PUT /workspace/setup': always(json(200, {'data': {'tenant': tenantAfterSetupJson(country: 'KW', currency: 'KWD', timezone: 'Asia/Kuwait')}})),
      });
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tapText(tester, 'Change');
      await pickCountry(tester, 'Kuwait');
      await saveNow(tester);

      final body = bodyOf(server);
      expect(body, {'name': 'Al-Fares Electronics', 'country': 'KW'});
      expect(body, isNot(contains('timezone')));
      expect(card, findsNothing);
      expect(accountOf(tester).country, 'KW');
      expect(accountOf(tester).currency, 'KWD');
      expect(accountOf(tester).setup!.timezone, 'Asia/Kuwait');
    });

    testWidgets('does not send a country that was opened but left as it was', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tapText(tester, 'Change');
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'Al-Fares Electronics'});
    });

    testWidgets('does not send a pick that was folded away again with Change', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tapText(tester, 'Change');
      await pickCountry(tester, 'Kuwait');
      await tapText(tester, 'Change');
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'Al-Fares Electronics'});
    });

    testWidgets('when the server could not tell, sends the country chosen and the name', (tester) async {
      final server = setupServer(account: accountWithSetup(detected: false, country: 'SA', currency: 'SAR', timezone: 'Asia/Riyadh'));
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await pickCountry(tester, 'Egypt');
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'Al-Fares Electronics', 'country': 'EG'});
    });

    testWidgets('sends only the name when the place is locked', (tester) async {
      final server = setupServer(account: accountWithSetup(canChange: false));
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'Al-Fares Electronics'});
    });

    testWidgets('asks for a name next to the field, and asks the server nothing, when it is empty or only spaces', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await saveNow(tester);
      expect(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.text('Enter the name of your business.')), findsOneWidget);

      await typeIntoKey(tester, 'setup-name', '    ');
      await saveNow(tester);

      expect(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.text('Enter the name of your business.')), findsOneWidget);
      expect(server.calls('PUT /workspace/setup'), 0);
      expect(card, findsOneWidget);
    });

    testWidgets('puts the server’s refusal of a region change next to the country, saves nothing and keeps what was typed', (tester) async {
      final server = setupServer(routes: {
        'PUT /workspace/setup': always(refused('country', 'The country can no longer be changed because this business already has contracts or payments.')),
      });
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tapText(tester, 'Change');
      await pickCountry(tester, 'Kuwait');
      await saveNow(tester);

      expect(find.descendant(of: find.byKey(const ValueKey('setup-country')), matching: find.textContaining('can no longer be changed')), findsOneWidget);
      expect(card, findsOneWidget);
      expect(accountOf(tester).setup!.confirmed, isFalse);
      expect(accountOf(tester).businessName, 'Business of Layla');
      final input = tester.widget<EditableText>(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.byType(EditableText)));
      expect(input.controller.text, 'Al-Fares Electronics');
      expect(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.textContaining('can no longer be changed')), findsNothing);
    });

    testWidgets('puts the server’s complaint about the name under the name', (tester) async {
      final server = setupServer(routes: {'PUT /workspace/setup': always(refused('name', 'The business name can be at most 255 characters.'))});
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'A business');
      await saveNow(tester);

      expect(find.descendant(of: find.byKey(const ValueKey('setup-name')), matching: find.text('The business name can be at most 255 characters.')), findsOneWidget);
      expect(card, findsOneWidget);
    });

    testWidgets('says so in the card when there is no connection, keeps what was typed, and a second try saves', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      server.offline = true;
      await saveNow(tester);

      expect(find.descendant(of: card, matching: find.text('No connection. Check your internet and try again.')), findsOneWidget);
      expect(card, findsOneWidget);

      server.offline = false;
      await saveNow(tester);

      expect(card, findsNothing);
      expect(find.text('No connection. Check your internet and try again.'), findsNothing);
      expect(server.calls('PUT /workspace/setup'), 2, reason: 'the first never got through');
      expect(bodyOf(server), {'name': 'Al-Fares Electronics'});
    });

    testWidgets('sends one request however many times it is tapped while the first is on its way', (tester) async {
      final gate = Completer<FakeResponse>();
      final server = setupServer(routes: {'PUT /workspace/setup': (_) => gate.future});
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tester.ensureVisible(saveButton);
      await tester.pump();
      for (var tap = 0; tap < 3; tap++) {
        await tester.tap(saveButton, warnIfMissed: false);
        await tester.pump(const Duration(milliseconds: 50));
      }

      expect(server.calls('PUT /workspace/setup'), 1);
      expect(find.descendant(of: saveButton, matching: find.byType(CircularProgressIndicator)), findsOneWidget, reason: 'the button shows it is working');

      gate.complete(json(200, {'data': {'tenant': tenantAfterSetupJson()}}));
      await settle(tester);

      expect(server.calls('PUT /workspace/setup'), 1);
      expect(card, findsNothing);
    });

    testWidgets('lets the controller itself refuse a second save while one is on its way', (tester) async {
      final gate = Completer<FakeResponse>();
      final server = setupServer(routes: {'PUT /workspace/setup': (_) => gate.future});
      await pumpApp(tester, server);

      final controller = containerOf(tester).read(setupControllerProvider.notifier);
      final results = <bool>[];
      unawaited(controller.save(name: 'Al-Fares Electronics').then(results.add));
      unawaited(controller.save(name: 'Al-Fares Electronics').then(results.add));
      await tester.pump();

      expect(results, [false], reason: 'the second is refused at once, while the first waits');
      gate.complete(json(200, {'data': {'tenant': tenantAfterSetupJson()}}));
      await settle(tester);

      expect(results, [false, true]);
      expect(server.calls('PUT /workspace/setup'), 1);
    });

    testWidgets('leaves a complaint behind when the person signs out, so the next one starts clean', (tester) async {
      final server = setupServer(routes: {'PUT /workspace/setup': always(refused('name', 'The business name can be at most 255 characters.'))});
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'A business');
      await saveNow(tester);
      expect(containerOf(tester).read(setupControllerProvider).nameError, isNotNull);

      final container = containerOf(tester);
      unawaited(container.read(authProvider.notifier).signOut());
      await settle(tester);

      expect(container.read(setupControllerProvider).nameError, isNull);
      expect(container.read(setupControllerProvider).busy, isFalse);
    });

    testWidgets('lets a screen reader hear why Save did nothing: the refusal is part of the country field’s semantics, and announced', (tester) async {
      final handle = tester.ensureSemantics();
      const refusal = 'The country can no longer be changed because this business already has contracts or payments.';
      final server = setupServer(routes: {'PUT /workspace/setup': always(refused('country', refusal))});
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await tapText(tester, 'Change');
      await pickCountry(tester, 'Kuwait');
      await saveNow(tester);

      final field = tester.getSemantics(find.byKey(const ValueKey('setup-country-button')));
      expect(field, isSemantics(isButton: true, hasTapAction: true, label: 'Country: Kuwait', hint: refusal, isLiveRegion: true));
      handle.dispose();
    });

    testWidgets('lets a screen reader hear the helper line of the country field, which is not an alarm', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, setupServer());

      await tapText(tester, 'Change');

      final field = tester.getSemantics(find.byKey(const ValueKey('setup-country-button')));
      expect(field, isSemantics(isButton: true, label: 'Country: Qatar', hint: 'The currency and time zone follow the country.', isLiveRegion: false));
      handle.dispose();
    });

    testWidgets('words the country field’s label as one sentence of the language, not a colon the app glues on', (tester) async {
      final handle = tester.ensureSemantics();
      final ar = words('ar');
      await pumpApp(tester, setupServer(), language: 'ar', size: const Size(360, 780));

      await tapText(tester, ar['Change'] as String);

      final label = (ar['Country: :name'] as String).replaceAll(':name', ar['Qatar'] as String);
      expect(tester.getSemantics(find.byKey(const ValueKey('setup-country-button'))), isSemantics(isButton: true, label: label));
      handle.dispose();
    });

    testWidgets('ignores the answer of a save made before another business’s account took over', (tester) async {
      final gate = Completer<FakeResponse>();
      final server = setupServer(routes: {'PUT /workspace/setup': (_) => gate.future});
      await pumpApp(tester, server);
      final container = containerOf(tester);

      final results = <bool>[];
      unawaited(container.read(setupControllerProvider.notifier).save(name: 'Al-Fares Electronics').then(results.add));
      await tester.pump();

      // Signed out and in as someone else, in another business, while the first save was on its way.
      final other = accountWithSetup();
      other['user'] = {...Map<String, dynamic>.from(other['user'] as Map), 'id': 'u2', 'name': 'Omar Nasser'};
      other['tenant'] = {...Map<String, dynamic>.from(other['tenant'] as Map), 'id': 't2', 'name': 'Business of Omar'};
      unawaited(container.read(authProvider.notifier).accountChanged(Account.fromJson(other)));
      await tester.pump();

      gate.complete(json(200, {'data': {'tenant': tenantAfterSetupJson(name: 'Al-Fares Electronics')}}));
      await settle(tester);

      expect(results, [false], reason: 'it was not applied');
      final account = accountOf(tester);
      expect(account.tenantId, 't2');
      expect(account.businessName, 'Business of Omar');
      expect(account.setup!.confirmed, isFalse, reason: 'the other business is still to be named');
      expect(card, findsOneWidget);
      expect(container.read(setupControllerProvider).busy, isFalse);
    });

    testWidgets('ignores the answer of a save made by another person of the same business, who is not the owner', (tester) async {
      final gate = Completer<FakeResponse>();
      final server = setupServer(routes: {'PUT /workspace/setup': (_) => gate.future});
      await pumpApp(tester, server);
      final container = containerOf(tester);

      final results = <bool>[];
      unawaited(container.read(setupControllerProvider.notifier).save(name: 'Al-Fares Electronics').then(results.add));
      await tester.pump();

      final member = accountWithSetup(role: 'manager');
      member['user'] = {...Map<String, dynamic>.from(member['user'] as Map), 'id': 'u2', 'name': 'Omar Nasser'};
      unawaited(container.read(authProvider.notifier).accountChanged(Account.fromJson(member)));
      await tester.pump();

      gate.complete(json(200, {'data': {'tenant': tenantAfterSetupJson(name: 'Al-Fares Electronics')}}));
      await settle(tester);

      expect(results, [false]);
      final account = accountOf(tester);
      expect(account.role, 'manager', reason: 'the owner’s role in the answer is not this person’s');
      expect(account.isOwner, isFalse);
      expect(account.businessName, 'Business of Layla');
      expect(card, findsNothing);
      expect(notice, findsOneWidget);
    });

    testWidgets('goes back to sign-in with the usual explanation when the session has ended, and shows no misleading message', (tester) async {
      final server = setupServer(routes: {'PUT /workspace/setup': always(apiError(401, 'unauthenticated', 'Unauthenticated.'))});
      await pumpApp(tester, server);
      final container = containerOf(tester);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await saveNow(tester);

      expect(find.text('Welcome back'), findsOneWidget);
      expect(find.text('Your session ended. Please sign in again.'), findsOneWidget);
      expect(find.text('Unauthenticated.'), findsNothing);
      expect(card, findsNothing);
      expect(server.calls('PUT /workspace/setup'), 1);
      expect(container.read(setupControllerProvider).busy, isFalse);
      expect(container.read(setupControllerProvider).failure, isNull, reason: 'nothing is left for whoever signs in next to see');
    });

    testWidgets('keeps the named business in the copy kept on the phone, so a fresh start with no connection shows no card', (tester) async {
      final server = setupServer();
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await saveNow(tester);

      final kept = (await preferences()).getString('account')!;
      final cached = Account.fromJson(jsonDecode(kept) as Map<String, dynamic>);
      expect(cached.businessName, 'Al-Fares Electronics');
      expect(cached.setup!.confirmed, isTrue);

      await tester.pumpWidget(const SizedBox());
      final offline = setupServer()..offline = true;
      await pumpApp(tester, offline, preferences: {'account': kept});

      expect(find.textContaining('You are offline'), findsOneWidget);
      expect(accountOf(tester).businessName, 'Al-Fares Electronics');
      expect(card, findsNothing);
      expect(notice, findsNothing);
    });

    testWidgets('tells any other refusal in the server’s words, inside the card', (tester) async {
      final server = setupServer(routes: {'PUT /workspace/setup': always(apiError(403, 'forbidden', 'This action is unauthorized.'))});
      await pumpApp(tester, server);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics');
      await saveNow(tester);

      expect(find.descendant(of: card, matching: find.text('This action is unauthorized.')), findsOneWidget);
      expect(card, findsOneWidget);
    });
  });

  group('a document asked for before the business has a name', () {
    FakeResponse refusedDocument() => apiError(409, 'workspace_setup_required', nameFirst);

    Future<void> askForStatement(WidgetTester tester) async {
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapTooltip(tester, 'Statement');
      await tapButton(tester, 'Share');
    }

    testWidgets('takes the owner back to Today, where the card is, with a short message', (tester) async {
      final server = setupServer(routes: {'GET /contracts/k1/statement.pdf': always(refusedDocument())});
      await pumpApp(tester, server);

      await askForStatement(tester);

      expect(find.text('Choose how it looks, then share or print it.'), findsNothing, reason: 'the sheet is closed');
      expect(card, findsOneWidget);
      expect(find.text(nameFirst), findsOneWidget);
      expect(find.text('Something went wrong. Please try again.'), findsNothing);
    });

    testWidgets('brings the card up even when the phone had not heard the business was unnamed', (tester) async {
      var named = true;
      final server = setupServer(routes: {
        'GET /me': (_) => json(200, {'data': accountWithSetup(confirmed: named)}),
        'GET /contracts/k1/statement.pdf': (_) {
          named = false;

          return refusedDocument();
        },
      });
      await pumpApp(tester, server);
      expect(card, findsNothing);

      await askForStatement(tester);

      expect(card, findsOneWidget);
      expect(find.text(nameFirst), findsOneWidget);
    });

    testWidgets('tells a member whose job it is, in the sheet, and opens no card', (tester) async {
      final server = setupServer(account: accountWithSetup(role: 'manager'), routes: {'GET /contracts/k1/statement.pdf': always(refusedDocument())});
      await pumpApp(tester, server);

      await askForStatement(tester);

      expect(find.text('Choose how it looks, then share or print it.'), findsOneWidget, reason: 'the sheet stays');
      expect(find.text(ownerNotice), findsOneWidget);
      expect(find.text(nameFirst), findsNothing);
      expect(card, findsNothing);
    });
  });

  group('the account', () {
    test('reads the card’s facts and keeps them when the account is stored and read back', () {
      final account = Account.fromJson(accountWithSetup(detected: false, canChange: false, timezone: 'Asia/Kuala_Lumpur'));

      final setup = account.setup!;
      expect(setup.confirmed, isFalse);
      expect(setup.country, 'QA');
      expect(setup.currency, 'QAR');
      expect(setup.timezone, 'Asia/Kuala_Lumpur');
      expect(setup.countryDetected, isFalse);
      expect(setup.canChangeRegion, isFalse);

      final again = Account.fromJson(jsonDecode(jsonEncode(account.toJson())) as Map<String, dynamic>).setup!;
      expect(again.confirmed, isFalse);
      expect(again.timezone, 'Asia/Kuala_Lumpur');
      expect(again.canChangeRegion, isFalse);
    });

    test('has no setup when the server leaves the key out, and no setup in what it stores', () {
      final account = Account.fromJson(accountWithSetup(setup: false));

      expect(account.setup, isNull);
      expect((account.toJson()['tenant'] as Map).containsKey('setup'), isFalse);
    });

    test('reads a card with missing or odd values without failing, and without nagging', () {
      expect(Account.fromJson({...accountJson(), 'tenant': {...accountJson()['tenant'] as Map<String, dynamic>, 'setup': 'soon'}}).setup, isNull);

      final bare = Account.fromJson({...accountJson(), 'tenant': {...accountJson()['tenant'] as Map<String, dynamic>, 'setup': <String, dynamic>{}}}).setup!;
      expect(bare.confirmed, isTrue, reason: 'a card the server did not describe is not shown');
      expect(bare.countryDetected, isFalse);
      expect(bare.canChangeRegion, isTrue, reason: 'the server still decides, and refuses on the country');
    });

    test('takes the workspace as the server describes it after the card is saved', () {
      final account = Account.fromJson(accountWithSetup());

      final named = account.withTenant(tenantAfterSetupJson(name: 'Al-Fares Electronics', country: 'KW', currency: 'KWD', timezone: 'Asia/Kuwait'));

      expect(named.businessName, 'Al-Fares Electronics');
      expect(named.country, 'KW');
      expect(named.currency, 'KWD');
      expect(named.setup!.confirmed, isTrue);
      expect(named.name, 'Layla Haddad', reason: 'the person is untouched');
      expect(named.entitlements.keys, account.entitlements.keys);
      expect(named.isOwner, isTrue);
    });
  });

  group('the countries', () {
    test('are the server’s thirty-five, and every one has a name of its own', () {
      expect(countryCodes, hasLength(35));
      expect(countryCodes.first, 'SA');
      expect(countryCodes.last, 'ZA');
    });

    test('name a zone by its city', () {
      expect(timeZoneCity('Asia/Qatar'), 'Qatar');
      expect(timeZoneCity('Asia/Kuala_Lumpur'), 'Kuala Lumpur');
      expect(timeZoneCity('America/Argentina/Buenos_Aires'), 'Buenos Aires');
      expect(timeZoneCity('UTC'), 'UTC');
      expect(timeZoneCity(''), '');
    });
  });

  group('in other languages and at large text', () {
    testWidgets('fits at 200% text in English on a small phone, whichever way the place goes', (tester) async {
      for (final account in [accountWithSetup(), accountWithSetup(detected: false), accountWithSetup(canChange: false), accountWithSetup(role: 'manager')]) {
        await pumpApp(tester, setupServer(account: account), size: const Size(320, 640), textScale: 2);

        expect(find.byKey(const ValueKey('setup-card')).evaluate().length + find.byKey(const ValueKey('setup-notice')).evaluate().length, 1);
        await tester.pumpWidget(const SizedBox());
      }
    });

    testWidgets('fits at 200% text with the chooser open, an error showing and a long name typed', (tester) async {
      final server = setupServer(routes: {'PUT /workspace/setup': always(refused('country', 'The country can no longer be changed because this business already has contracts or payments.'))});
      await pumpApp(tester, server, size: const Size(320, 640), textScale: 2);

      await typeIntoKey(tester, 'setup-name', 'Al-Fares Electronics and Home Appliances Trading Company');
      await tapText(tester, 'Change');
      await pickCountry(tester, 'United Arab Emirates');
      await saveNow(tester);

      expect(find.descendant(of: find.byKey(const ValueKey('setup-country')), matching: find.textContaining('can no longer be changed')), findsOneWidget);
    });

    testWidgets('speaks Arabic, right to left, and sends the same thing', (tester) async {
      final ar = words('ar');
      final server = setupServer();
      await pumpApp(tester, server, language: 'ar', size: const Size(360, 780));

      expect(find.text(ar['Name your business'] as String), findsOneWidget);
      expect(find.text(ar['It is printed on every receipt and statement.'] as String), findsOneWidget);
      expect(find.text(ar['Save and continue'] as String), findsOneWidget);
      final line = (ar['We think you are in :country: :currency, :zone.'] as String).replaceAll(':country', ar['Qatar'] as String).replaceAll(':currency', 'QAR').replaceAll(':zone', 'Qatar');
      expect(find.text(line), findsOneWidget);
      expect(Directionality.of(tester.element(card)), TextDirection.rtl);

      await typeIntoKey(tester, 'setup-name', 'متجر الفارس');
      await tapText(tester, ar['Change'] as String);
      await pickCountry(tester, ar['Kuwait'] as String);
      await saveNow(tester);

      expect(bodyOf(server), {'name': 'متجر الفارس', 'country': 'KW'});
    });

    testWidgets('fits in Arabic at 200% text, for the owner, a member and a business with a locked place', (tester) async {
      for (final account in [accountWithSetup(), accountWithSetup(detected: false), accountWithSetup(canChange: false), accountWithSetup(role: 'viewer')]) {
        await pumpApp(tester, setupServer(account: account), language: 'ar', size: const Size(320, 640), textScale: 2);

        expect(find.byKey(const ValueKey('setup-card')).evaluate().length + find.byKey(const ValueKey('setup-notice')).evaluate().length, 1);
        await tester.pumpWidget(const SizedBox());
      }
    });

    for (final language in ['fr', 'es', 'ur']) {
      testWidgets('speaks $language: the heading, the button and the member’s line', (tester) async {
        final table = words(language);
        await pumpApp(tester, setupServer(), language: language);
        expect(find.text(table['Name your business'] as String), findsOneWidget);
        expect(find.text(table['Save and continue'] as String), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
        await pumpApp(tester, setupServer(account: accountWithSetup(role: 'viewer')), language: language);
        expect(find.text(table[ownerNotice] as String), findsOneWidget);
      });
    }
  });
}
