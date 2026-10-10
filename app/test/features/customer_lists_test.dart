import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/features/customers/contact_picker.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP12: lists that stay short. Sort, tags, pins, picking from contacts, and archived contracts.

Map<String, dynamic> tagsOnAccount() {
  final account = accountJson();
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    'customer_tags': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
  };

  return account;
}

Map<String, dynamic> tagJson({String id = 'g1', String name = 'Shop 2', String colour = 'gold', int customers = 1}) =>
    {'id': id, 'name': name, 'colour': colour, 'customers': customers};

Map<String, dynamic> taggedCustomer({bool pinned = false}) => {
      ...customerJson(),
      'pinned': pinned,
      'tags': [
        {'id': 'g1', 'name': 'Shop 2', 'colour': 'gold'},
      ],
    };

Map<String, dynamic> sentBody(FakeServer server, String route) => jsonDecode(server.requestsTo(route).last.data as String) as Map<String, dynamic>;

void main() {
  group('sorting the customers', () {
    testWidgets('asks the server for the order chosen, and keeps it', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      expect(server.requestsTo('GET /customers').last.queryParameters['sort'], isNull);

      await tapTooltip(tester, 'Sort');
      await tapText(tester, 'What they owe', last: true);

      expect(server.requestsTo('GET /customers').last.queryParameters['sort'], 'balance');

      await tapTooltip(tester, 'Sort');
      await tapText(tester, 'Next due date', last: true);
      expect(server.requestsTo('GET /customers').last.queryParameters['sort'], 'next_due');
    });
  });

  testWidgets('opens in the order last chosen', (tester) async {
    final server = workspaceServer();
    await pumpApp(tester, server, preferences: {'customer_sort': 'activity'});
    await tapText(tester, 'Customers', last: true);

    expect(server.requestsTo('GET /customers').first.queryParameters['sort'], 'activity');
  });

  testWidgets('remembers the order chosen', (tester) async {
    final server = workspaceServer();
    await pumpApp(tester, server);
    await tapText(tester, 'Customers', last: true);
    await tapTooltip(tester, 'Sort');
    await tapText(tester, 'Last activity', last: true);

    expect((await preferences()).getString('customer_sort'), 'activity');
  });

  group('tags', () {
    testWidgets('a tag chip filters the list, and All shows everyone again', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson(), tagJson(id: 'g2', name: 'Government staff', colour: 'blue')]})),
        'GET /customers': always(json(200, pageJson([taggedCustomer()], page: 1, last: 1, total: 1))),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      await tapText(tester, 'Government staff');
      expect(server.requestsTo('GET /customers').last.queryParameters['tag'], 'g2');

      await tapText(tester, 'All');
      expect(server.requestsTo('GET /customers').last.queryParameters['tag'], isNull);
    });

    testWidgets('each customer shows their tags', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson()]})),
        'GET /customers': always(json(200, pageJson([taggedCustomer()], page: 1, last: 1, total: 1))),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      // One chip to filter by, one on the customer's row.
      expect(find.text('Shop 2'), findsNWidgets(2));
    });

    testWidgets('while tags are switched off, nothing about them shows or is asked for', (tester) async {
      final server = workspaceServer(routes: {
        'GET /customers': always(json(200, pageJson([taggedCustomer()], page: 1, last: 1, total: 1))),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      expect(server.calls('GET /tags'), 0);
      expect(find.text('Shop 2'), findsNothing);
    });

    testWidgets('the form offers the business’s tags and saves the ones chosen', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson(), tagJson(id: 'g2', name: 'Government staff', colour: 'blue')]})),
        'POST /customers': always(json(201, {'data': {...customerJson(id: 'c2', name: 'Sara Al-Qahtani'), 'tags': <Object>[]}})),
        'GET /customers/c2': always(json(200, {'data': {...customerJson(id: 'c2', name: 'Sara Al-Qahtani'), 'contracts': <Map<String, dynamic>>[]}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await typeInto(tester, 'Full name', 'Sara Al-Qahtani');
      await typeInto(tester, 'Phone', '+966541110099');
      await tapText(tester, 'Government staff', last: true);
      await tapButton(tester, 'Add customer');

      expect(sentBody(server, 'POST /customers')['tags'], ['g2']);
    });

    testWidgets('editing keeps the tags the customer has, and clears them when all are taken off', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson()]})),
        'GET /customers/c1': always(json(200, {'data': {...taggedCustomer(), 'contracts': <Map<String, dynamic>>[]}})),
        'PUT /customers/c1': always(json(200, {'data': {...customerJson(), 'tags': <Object>[]}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Ahmad Salem');
      await tapTooltip(tester, 'Edit');

      final chip = tester.widget<FilterChip>(find.widgetWithText(FilterChip, 'Shop 2'));
      expect(chip.selected, isTrue);

      await tapText(tester, 'Shop 2', last: true);
      await tapButton(tester, 'Save changes');

      expect(sentBody(server, 'PUT /customers/c1')['tags'], isEmpty);
    });

    testWidgets('a form without tags switched on sends none, so the server leaves them as they are', (tester) async {
      final server = workspaceServer(routes: {
        'GET /tags': always(json(200, {'data': [tagJson()]})),
        'GET /customers/c1': always(json(200, {'data': {...taggedCustomer(), 'contracts': <Map<String, dynamic>>[]}})),
        'PUT /customers/c1': always(json(200, {'data': customerJson()})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Ahmad Salem');
      await tapTooltip(tester, 'Edit');
      await tapButton(tester, 'Save changes');

      expect(sentBody(server, 'PUT /customers/c1').containsKey('tags'), isFalse);
      expect(server.calls('GET /tags'), 0);
    });
  });

  group('the tags screen', () {
    testWidgets('lists the tags, from settings, and adds one in the colour chosen', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson(customers: 4)]})),
        'POST /tags': always(json(201, {'data': tagJson(id: 'g3', name: 'Market stall', colour: 'blue', customers: 0)})),
      });
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Customer tags');

      expect(find.text('Shop 2'), findsOneWidget);
      expect(find.text('Customers: 4'), findsOneWidget);

      await tapTooltip(tester, 'Add a tag');
      await typeInto(tester, 'Name', '  Market stall ');
      await tapText(tester, 'Blue');
      await tapButton(tester, 'Add tag');

      expect(sentBody(server, 'POST /tags'), {'name': 'Market stall', 'colour': 'blue'});
      expect(find.text('Tag added.'), findsOneWidget);
    });

    testWidgets('a name already taken is said in the sheet, which stays open', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson()]})),
        'POST /tags': always(apiError(422, 'validation_failed', 'The given data was invalid.', extra: {'fields': {'name': ['There is already a tag called Shop 2.']}})),
      });
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Customer tags');
      await tapTooltip(tester, 'Add a tag');
      await typeInto(tester, 'Name', 'shop 2');
      await tapButton(tester, 'Add tag');

      expect(find.text('There is already a tag called Shop 2.'), findsOneWidget);
      expect(find.widgetWithText(QButton, 'Add tag'), findsOneWidget);
    });

    testWidgets('removing a tag asks first, then lets it go', (tester) async {
      final server = workspaceServer(account: tagsOnAccount(), routes: {
        'GET /tags': always(json(200, {'data': [tagJson()]})),
        'DELETE /tags/g1': always(json(204, <String, dynamic>{})),
      });
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Customer tags');
      await tapTooltip(tester, 'More');
      await tapText(tester, 'Remove this tag');

      expect(server.calls('DELETE /tags/g1'), 0);
      await tapText(tester, 'Remove', last: true);

      expect(server.calls('DELETE /tags/g1'), 1);
      expect(find.text('Tag removed. Its customers are unchanged.'), findsOneWidget);
    });

    testWidgets('no tags row in settings while tags are switched off', (tester) async {
      await pumpApp(tester, workspaceServer());
      await openSettings(tester);

      expect(find.text('Customer tags'), findsNothing);
    });
  });

  group('the customer page', () {
    testWidgets('folds archived contracts away under their count', (tester) async {
      final server = workspaceServer(routes: {
        'GET /customers/c1': always(json(200, {
          'data': {
            ...customerJson(),
            'contracts': [
              {...contractJson(status: 'settled', state: 'settled'), 'archived': true},
            ],
          },
        })),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Ahmad Salem');

      expect(find.text('Archived contracts: 1'), findsOneWidget);
      expect(find.text('C-0007'), findsNothing);

      await tapText(tester, 'Archived contracts: 1');
      expect(find.text('C-0007'), findsOneWidget);
    });

    testWidgets('pins the customer from their page', (tester) async {
      final server = workspaceServer(routes: {
        'POST /customers/c1/pin': always(json(200, {'data': {...customerJson(), 'pinned': true}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Ahmad Salem');
      await tapTooltip(tester, 'Pin to the top');

      expect(server.calls('POST /customers/c1/pin'), 1);
    });
  });

  group('pins', () {
    testWidgets('a pinned customer carries a pin', (tester) async {
      final server = workspaceServer(routes: {
        'GET /customers': always(json(200, pageJson([taggedCustomer(pinned: true)], page: 1, last: 1, total: 1))),
      });
      final handle = tester.ensureSemantics();
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      expect(find.bySemanticsLabel(RegExp('Pinned')), findsOneWidget);
      handle.dispose();
    });

    testWidgets('a long press pins a customer to the top', (tester) async {
      final server = workspaceServer(routes: {
        'POST /customers/c1/pin': always(json(200, {'data': {...customerJson(), 'pinned': true}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      await tester.longPress(find.text('Ahmad Salem'));
      await settle(tester);
      await tapText(tester, 'Pin to the top');

      expect(server.calls('POST /customers/c1/pin'), 1);
      expect(find.text('Ahmad Salem is pinned to the top of your lists.'), findsOneWidget);
    });

    testWidgets('and unpins one that is pinned', (tester) async {
      final server = workspaceServer(routes: {
        'GET /customers': always(json(200, pageJson([taggedCustomer(pinned: true)], page: 1, last: 1, total: 1))),
        'DELETE /customers/c1/pin': always(json(200, {'data': {...customerJson(), 'pinned': false}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      await tester.longPress(find.text('Ahmad Salem'));
      await settle(tester);
      await tapText(tester, 'Unpin');

      expect(server.calls('DELETE /customers/c1/pin'), 1);
      expect(server.calls('POST /customers/c1/pin'), 0);
    });

    testWidgets('a viewer cannot pin', (tester) async {
      final server = workspaceServer(account: accountJson(role: 'viewer'));
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);

      await tester.longPress(find.text('Ahmad Salem'));
      await settle(tester);

      expect(find.text('Pin to the top'), findsNothing);
    });
  });

  group('picking from contacts', () {
    testWidgets('fills the phone in international form, and the name when it is empty', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, overrides: [
        contactPickerProvider.overrideWithValue(() async => (name: 'Sara Al-Qahtani', phone: '054 111 0099')),
      ]);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await tapText(tester, 'Pick from contacts');

      expect(find.text('+966541110099'), findsOneWidget);
      expect(find.text('Sara Al-Qahtani'), findsOneWidget);
    });

    testWidgets('never writes over a name already typed', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, overrides: [
        contactPickerProvider.overrideWithValue(() async => (name: 'Sara Q.', phone: '+966 54 111 0099')),
      ]);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await typeInto(tester, 'Full name', 'Sara Al-Qahtani');
      await tapText(tester, 'Pick from contacts');

      expect(find.text('Sara Al-Qahtani'), findsOneWidget);
      expect(find.text('Sara Q.'), findsNothing);
      expect(find.text('+966541110099'), findsOneWidget);
    });

    testWidgets('closing the contacts without choosing changes nothing', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, overrides: [contactPickerProvider.overrideWithValue(() async => null)]);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await typeInto(tester, 'Phone', '0500000000');
      await tapText(tester, 'Pick from contacts');

      expect(find.text('0500000000'), findsOneWidget);
    });
  });

  group('international phone numbers', () {
    test('a local number gains the country code', () {
      expect(internationalPhone('054 111 0099', 'SA'), '+966541110099');
      expect(internationalPhone('050-123-4567', 'AE'), '+971501234567');
    });

    test('a number already international keeps its code', () {
      expect(internationalPhone('+20 100 123 4567', 'SA'), '+201001234567');
      expect(internationalPhone('00971501234567', 'SA'), '+971501234567');
    });

    test('a number it cannot place is left as it was', () {
      expect(internationalPhone('541110099', 'SA'), '541110099');
      expect(internationalPhone('0541110099', 'XX'), '0541110099');
      expect(internationalPhone('12', 'SA'), '12');
    });
  });

  group('archived contracts', () {
    testWidgets('a settled contract can be archived from its page', (tester) async {
      final server = workspaceServer(routes: {
        'GET /contracts/k1': always(json(200, {'data': contractJson(status: 'settled', state: 'settled')})),
        'POST /contracts/k1/archive': always(json(200, {'data': {...contractJson(status: 'settled', state: 'settled'), 'archived': true}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      await tapTooltip(tester, 'Archive');

      expect(server.calls('POST /contracts/k1/archive'), 1);
      expect(find.text('Contract C-0007 archived. It is under Archived in your contracts.'), findsOneWidget);
    });

    testWidgets('an archived contract comes back to the lists', (tester) async {
      final server = workspaceServer(routes: {
        'GET /contracts/k1': always(json(200, {'data': {...contractJson(status: 'cancelled', state: 'cancelled'), 'archived': true}})),
        'POST /contracts/k1/unarchive': always(json(200, {'data': contractJson(status: 'cancelled', state: 'cancelled')})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      await tapTooltip(tester, 'Bring back to the lists');

      expect(server.calls('POST /contracts/k1/unarchive'), 1);
      expect(find.text('Contract C-0007 is back in your lists.'), findsOneWidget);
    });

    testWidgets('a running contract cannot be archived', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      expect(find.byTooltip('Archive'), findsNothing);
    });

    testWidgets('a viewer cannot archive', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(role: 'viewer'), routes: {
        'GET /contracts/k1': always(json(200, {'data': contractJson(status: 'settled', state: 'settled')})),
      }));
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      expect(find.byTooltip('Archive'), findsNothing);
    });

    testWidgets('the contracts list has an Archived view', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);

      await tapText(tester, 'Archived');

      expect(server.requestsTo('GET /contracts').last.queryParameters['status'], 'archived');
    });
  });
}
