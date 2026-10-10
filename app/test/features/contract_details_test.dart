import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/features/products/scanner.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Contract details (Win Plan PP7) and the discount at sale (PP6): what was sold, scanned or picked from the products
/// list, a discount that comes off the price before the schedule, and the customer's job.

Map<String, dynamic> accountWithDetails() {
  final account = accountJson();
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    'contract_items': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
  };

  return account;
}

Map<String, dynamic> productsJson() => {
      'data': [
        {'id': 'p1', 'name': 'Galaxy S25', 'sku': 'S25', 'default_price': '3499.00', 'cost': '2900.00', 'archived': false},
      ],
    };

Future<FakeServer> openForm(WidgetTester tester, {Future<String?> Function(BuildContext)? scanner}) async {
  final server = workspaceServer(account: accountWithDetails(), routes: {
    'GET /products': always(json(200, productsJson())),
    'POST /contracts': always(json(201, {'data': contractJson()})),
  });
  await pumpApp(tester, server, overrides: [if (scanner != null) barcodeScannerProvider.overrideWithValue(scanner)]);
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'New contract');
  await tapText(tester, 'Choose a customer');
  await tapText(tester, 'Ahmad Salem');

  return server;
}

Map<String, dynamic> sent(FakeServer server) => jsonDecode(server.requestsTo('POST /contracts').single.data as String) as Map<String, dynamic>;

void main() {
  testWidgets('scanning fills in the serial of what was sold', (tester) async {
    final server = await openForm(tester, scanner: (_) async => '490154203237518');

    await tapButton(tester, 'Add an item');
    await typeIntoKey(tester, 'item-name-0', 'iPhone 16 Pro');
    await tapTooltip(tester, 'Scan the serial or IMEI');
    await typeInto(tester, 'Sale price (SAR)', '1200');
    await tapButton(tester, 'Open contract');

    final items = (sent(server)['items'] as List<dynamic>).cast<Map<String, dynamic>>();
    expect(items.single['name'], 'iPhone 16 Pro');
    expect(items.single['serial'], '490154203237518');
  });

  testWidgets('says when the serial is already on another running contract, once the contract is open', (tester) async {
    const warning = 'SN-0042 is also on contract C-0001, which is still running.';
    final server = workspaceServer(account: accountWithDetails(), routes: {
      'GET /products': always(json(200, productsJson())),
      'POST /contracts': always(json(201, {
        'data': contractJson(),
        'meta': {
          'warnings': [
            {'field': 'items.0.serial', 'message': warning},
          ],
        },
      })),
    });
    await pumpApp(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');
    await tapText(tester, 'Choose a customer');
    await tapText(tester, 'Ahmad Salem');

    await tapButton(tester, 'Add an item');
    await typeIntoKey(tester, 'item-name-0', 'Phone');
    await typeInto(tester, 'Serial or IMEI', 'SN-0042');
    await typeInto(tester, 'Sale price (SAR)', '1200');
    await tapButton(tester, 'Open contract');

    expect(find.text(warning), findsOneWidget);
  });

  testWidgets('picking a product fills in its name, price and cost, and the price of the sale', (tester) async {
    final server = await openForm(tester);

    await tapButton(tester, 'Pick a product');
    await tapText(tester, 'Galaxy S25', last: true);
    await tapButton(tester, 'Open contract');

    final body = sent(server);
    final item = (body['items'] as List<dynamic>).cast<Map<String, dynamic>>().single;
    expect(item, {'name': 'Galaxy S25', 'quantity': 1, 'price': '3499.00', 'cost': '2900.00', 'product_id': 'p1'});
    expect(body['principal'], '3499.00');
  });

  testWidgets('an item row left blank is not sent', (tester) async {
    final server = await openForm(tester);

    await tapButton(tester, 'Add an item');
    await typeInto(tester, 'Sale price (SAR)', '1200');
    await tapButton(tester, 'Open contract');

    expect(sent(server)['items'] ?? const <Object?>[], isEmpty);
  });

  testWidgets('a discount comes off the price before the schedule, and is sent', (tester) async {
    final server = await openForm(tester);

    await typeInto(tester, 'Sale price (SAR)', '1200');
    await tapText(tester, 'An amount');
    await typeIntoKey(tester, 'discount-value', '200');

    expect(find.text('SAR 1,000.00'), findsWidgets);
    expect(find.text('Discount'), findsOneWidget);
    expect(find.text('SAR 200.00'), findsOneWidget);
    await tapButton(tester, 'Open contract');

    final body = sent(server);
    expect(body['principal'], '1200.00');
    expect(body['discount_type'], 'fixed');
    expect(body['discount_value'], '200.00');
  });

  testWidgets('keeps a list of products in Settings, and adds one', (tester) async {
    final server = workspaceServer(account: accountWithDetails(), routes: {
      'GET /products': always(json(200, {'data': <Object?>[]})),
      'POST /products': always(json(201, {'data': {'id': 'p2', 'name': 'Fridge', 'default_price': '2400.00', 'cost': null, 'archived': false}})),
    });
    await pumpApp(tester, server);
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-products')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-products')));
    await settle(tester);

    await tapTooltip(tester, 'Add a product');
    await typeInto(tester, 'Name', 'Fridge');
    await typeInto(tester, 'Price (optional)', '2400');
    await tapButton(tester, 'Add product');

    final body = jsonDecode(server.requestsTo('POST /products').single.data as String) as Map<String, dynamic>;
    expect(body, {'name': 'Fridge', 'default_price': '2400.00'});
  });

  testWidgets('keeps the customer’s job', (tester) async {
    final server = workspaceServer(routes: {'POST /customers': always(json(201, {'data': customerJson()}))});
    await pumpApp(tester, server);
    await tapText(tester, 'Customers', last: true);
    await tapText(tester, 'Add customer');
    await typeInto(tester, 'Full name', 'Huda Saleh');
    await typeInto(tester, 'Phone', '0551234567');
    await typeInto(tester, 'Job or employer (optional)', 'Nurse');
    await tapButton(tester, 'Add customer');

    final body = jsonDecode(server.requestsTo('POST /customers').single.data as String) as Map<String, dynamic>;
    expect(body['job'], 'Nurse');
  });
}
