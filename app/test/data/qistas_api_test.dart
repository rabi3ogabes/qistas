import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/api/api_client.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/data/qistas_api.dart';

import '../support/fake_api.dart';
import '../support/samples.dart';

QistasApi apiWith(FakeAdapter adapter) => QistasApi(ApiClient(
      baseUrl: 'https://qistas.test/api/v1',
      tokens: MemoryTokenStore('qst_token'),
      language: () => 'en',
      onUnauthorized: () {},
      adapter: adapter,
    ));

void main() {
  group('signing in', () {
    test('sends the credentials and the device, and reads the account', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': sessionJson()}));

      final signedIn = await apiWith(adapter).login(email: 'layla@example.com', password: 'secret', deviceName: 'Pixel 9');

      expect(adapter.last.path, 'auth/login');
      expect(adapter.lastBody, {'email': 'layla@example.com', 'password': 'secret', 'device_name': 'Pixel 9'});
      expect(signedIn.token, 'qst_new-token');
      expect(signedIn.account.name, 'Layla Haddad');
      expect(signedIn.account.currency, 'SAR');
      expect(signedIn.account.isFree, isTrue);
      expect(signedIn.account.entitlement('customers').limit, 5);
      expect(signedIn.account.entitlement('customers').used, 3);
    });

    test('adds the two-factor code only when there is one', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': sessionJson()}));
      final api = apiWith(adapter);

      await api.login(email: 'a@b.c', password: 'x', deviceName: 'd', code: '123456');
      expect(adapter.lastBody['code'], '123456');

      await api.login(email: 'a@b.c', password: 'x', deviceName: 'd', recoveryCode: 'abcde-12345');
      expect(adapter.lastBody.containsKey('code'), isFalse);
      expect(adapter.lastBody['recovery_code'], 'abcde-12345');
    });

    test('registering sends everything the server needs, including the confirmation and terms', () async {
      final adapter = FakeAdapter((_) => json(201, {'data': sessionJson()}));

      await apiWith(adapter).register(
        name: 'Layla', email: 'l@e.com', password: 'S3cure!Passw0rd', businessName: 'Al-Fares', country: 'SA', locale: 'ar', deviceName: 'Pixel 9',
      );

      expect(adapter.lastBody, {
        'name': 'Layla', 'email': 'l@e.com', 'password': 'S3cure!Passw0rd', 'password_confirmation': 'S3cure!Passw0rd',
        'business_name': 'Al-Fares', 'country': 'SA', 'terms': true, 'locale': 'ar', 'device_name': 'Pixel 9',
      });
    });
  });

  group('the account', () {
    test('treats a feature the server did not list as allowed, and a missing role as read-only', () {
      final account = sampleAccount(role: 'viewer');

      expect(account.entitlement('something_new').allowsMore, isTrue);
      expect(account.canWrite, isFalse);
      expect(account.canDelete, isFalse);
      expect(sampleAccount(role: 'manager').canDelete, isTrue);
      expect(sampleAccount(role: 'collector').canWrite, isTrue);
      expect(sampleAccount(role: 'collector').canDelete, isFalse);
    });

    test('knows when an allowance is used up', () {
      final account = sampleAccount();

      expect(account.entitlement('customers').allowsMore, isTrue);
      expect(account.entitlement('customers').fraction, closeTo(0.6, 1e-9));
      expect(sampleAccount(customersUsed: 5).entitlement('customers').allowsMore, isFalse);
      expect(account.entitlement('export_csv').allowsMore, isFalse);
      expect(account.entitlement('export_csv').fraction, isNull);
      expect(sampleAccount(plan: 'pro').entitlement('customers').allowsMore, isTrue);
    });

    test('survives being written down and read back (for the offline copy)', () {
      final account = sampleAccount();

      final again = sampleAccountFromJson(account.toJson());

      expect(again.name, account.name);
      expect(again.entitlement('customers').used, 3);
      expect(again.currency, 'SAR');
    });
  });

  group('customers', () {
    test('lists a page with what each owes, and asks for the next one', () async {
      final adapter = FakeAdapter((_) => json(200, customerPageJson()));

      final page = await apiWith(adapter).customers(query: '  ahmad ', page: 2);

      expect(adapter.last.queryParameters, {'q': 'ahmad', 'page': 2});
      expect(page.items.single.name, 'Ahmad Salem');
      expect(page.items.single.owed, Money.parse('200.00'));
      expect(page.items.single.runningContracts, 1);
      expect(page.hasMore, isTrue);
      expect(page.total, 12);
    });

    test('creates with what the form holds, trimmed', () async {
      final adapter = FakeAdapter((_) => json(201, {'data': customerJson()}));

      await apiWith(adapter).createCustomer(const CustomerForm(name: '  Ahmad ', phone: ' +966 50 123 4567 ', email: 'a@b.co'));

      expect(adapter.lastBody['name'], 'Ahmad');
      expect(adapter.lastBody['phone'], '+966 50 123 4567');
      expect(adapter.lastBody.containsKey('remove_national_id'), isFalse);
    });

    test('can ask for the national ID to be removed', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': customerJson()}));

      await apiWith(adapter).updateCustomer('c1', const CustomerForm(name: 'A', phone: '123456', removeNationalId: true));

      expect(adapter.last.method, 'PUT');
      expect(adapter.lastBody['remove_national_id'], isTrue);
    });
  });

  group('contracts', () {
    test('reads the schedule and the ledger of one contract', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': contractJson()}));

      final contract = await apiWith(adapter).contract('k1');

      expect(contract.reference, 'C-0007');
      expect(contract.total, Money.parse('1100.00'));
      expect(contract.owed, Money.parse('825.00'));
      expect(contract.installments.map((i) => i.state).toList(), ['paid', 'upcoming', 'upcoming', 'upcoming']);
      expect(contract.installments.first.amount, Money.parse('275.00'));
      expect(contract.transactions.single.amount, Money.parse('275.00'));
      expect(contract.transactions.single.canVoid, isTrue);
      expect(contract.next!.number, 2);
      expect(contract.customerName, 'Ahmad Salem');
      expect(contract.isRunning, isTrue);
      expect(contract.isLate, isFalse);
      expect(contract.takesPayments, isTrue);
    });

    test('a cash sale sends only what a cash sale needs', () {
      const form = ContractForm(customerId: 'c1', type: 'cash', principal: '450.00', startDate: '2026-10-07', downPayment: '50', installmentCount: 9);

      expect(form.toJson(), {'customer_id': 'c1', 'type': 'cash', 'principal': '450.00', 'start_date': '2026-10-07'});
    });

    test('an instalment contract sends its plan', () {
      const form = ContractForm(
        customerId: 'c1', type: 'scheduled', principal: '1200.00', downPayment: '200.00', markupType: 'percent', markupValue: '10',
        installmentCount: 4, frequency: 'monthly', startDate: '2026-10-07', firstDueDate: '2026-11-07',
      );

      expect(form.toJson(), {
        'customer_id': 'c1', 'type': 'scheduled', 'principal': '1200.00', 'down_payment': '200.00', 'markup_type': 'percent',
        'markup_value': '10', 'installment_count': 4, 'frequency': 'monthly', 'start_date': '2026-10-07', 'first_due_date': '2026-11-07',
      });
    });

    test('filters by list and search', () async {
      final adapter = FakeAdapter((_) => json(200, contractPageJson()));

      final page = await apiWith(adapter).contracts(status: 'late', query: 'C-0007');

      expect(adapter.last.queryParameters, {'status': 'late', 'q': 'C-0007', 'page': 1});
      expect(page.items.single.reference, 'C-0007');
    });
  });

  group('payments', () {
    test('records a payment with an exact amount and the idempotency key', () async {
      final adapter = FakeAdapter((_) => json(201, {'data': lineJson()}));

      final line = await apiWith(adapter).recordPayment('k1', amount: Money.parse('275'), method: 'bank_transfer', idempotencyKey: 'app-abc123', note: ' Transfer 8841 ', paidOn: DateTime(2026, 10, 3));

      expect(adapter.last.path, 'contracts/k1/payments');
      expect(adapter.last.headers['Idempotency-Key'], 'app-abc123');
      expect(adapter.lastBody, {'amount': '275.00', 'method': 'bank_transfer', 'note': 'Transfer 8841', 'paid_at': '2026-10-03'});
      expect(line.amount, Money.parse('275.00'));
      expect(line.contractOwed, Money.parse('825.00'));
    });

    test('a key looks the way the server accepts and is different every time', () {
      final keys = {for (var i = 0; i < 50; i++) newIdempotencyKey()};

      expect(keys.length, 50);
      expect(keys.every((k) => RegExp(r'^[A-Za-z0-9_.:\-]{1,100}$').hasMatch(k)), isTrue);
    });

    test('voids with a reason', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': lineJson(type: 'reversal', amount: '-275.00')}));

      final line = await apiWith(adapter).voidPayment('t1', reason: 'Wrong contract');

      expect(adapter.last.path, 'payments/t1/void');
      expect(adapter.lastBody, {'reason': 'Wrong contract'});
      expect(line.isReversal, isTrue);
      expect(line.amount.isNegative, isTrue);
    });
  });

  group('the dashboard and the plans', () {
    test('read the headline figures and who pays today', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': dashboardJson()}));

      final board = await apiWith(adapter).dashboard();

      expect(board.outstanding, Money.parse('5000.00'));
      expect(board.overdue, Money.parse('300.00'));
      expect(board.activeCustomers, 4);
      expect(board.dueToday.single.reference, 'C-0007');
      expect(board.dueToday.single.phone, '+966501234567');
      expect(board.overdueList.single.daysLate, 6);
      expect(board.upcoming.single.daysUntil, 2);
      expect(board.daily, hasLength(14));
      expect(board.expectedThisMonth, Money.parse('1920.00'));
      expect(board.toMatchLastMonth, Money.parse('300.00'));
      expect(board.beatLastMonth, isFalse);
      expect(board.collectionFraction, closeTo(0.625, 0.0001));
      expect(board.needsYou.map((d) => d.reference), ['C-0003', 'C-0007']);
      expect(board.collectionRate, '62.5');
    });

    test('plans carry what the admin chose, in the reader’s words', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': plansJson()}));

      final plans = await apiWith(adapter).plans();

      expect(plans.map((p) => p.key).toList(), ['free', 'pro']);
      expect(plans.first.isFree, isTrue);
      expect(plans.last.monthlyPrice, Money.parse('12.00'));
      expect(plans.last.yearlySavingPercent, 17);
      expect(plans.first.features.firstWhere((f) => f.key == 'customers').summary, 'Up to 5 customers');
      expect(plans.first.features.firstWhere((f) => f.key == 'export_csv').enabled, isFalse);
    });
  });
}
