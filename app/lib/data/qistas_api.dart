import 'dart:math';

import '../core/api/api_client.dart';
import '../core/money.dart';
import 'models.dart';

/// A fresh key for one attempt at one payment. Showing the same key again for the same attempt (a double tap, a
/// retry after a dropped connection) makes the server record one payment, not two.
String newIdempotencyKey([Random? random]) {
  final source = random ?? Random.secure();
  final bytes = List<int>.generate(12, (_) => source.nextInt(256));

  return 'app-${bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join()}';
}

/// Every call the app makes, typed. The screens never see JSON.
class QistasApi {
  QistasApi(this._client);

  final ApiClient _client;

  Map<String, dynamic> _data(Map<String, dynamic> json) => json['data'] as Map<String, dynamic>;

  // ------------------------------------------------------------------ account

  Future<SignedIn> login({
    required String email,
    required String password,
    required String deviceName,
    String? code,
    String? recoveryCode,
  }) async {
    final json = await _client.post('/auth/login', body: {
      'email': email,
      'password': password,
      'device_name': deviceName,
      if (code != null && code.isNotEmpty) 'code': code,
      if (recoveryCode != null && recoveryCode.isNotEmpty) 'recovery_code': recoveryCode,
    });

    return _signedIn(json);
  }

  Future<SignedIn> register({
    required String name,
    required String email,
    required String password,
    required String businessName,
    required String country,
    required String locale,
    required String deviceName,
  }) async {
    final json = await _client.post('/auth/register', body: {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': password,
      'business_name': businessName,
      'country': country,
      'terms': true,
      'locale': locale,
      'device_name': deviceName,
    });

    return _signedIn(json);
  }

  SignedIn _signedIn(Map<String, dynamic> json) {
    final data = _data(json);

    return SignedIn(token: data['token'].toString(), account: Account.fromJson(data));
  }

  /// Whether "Try the demo" is on. Public, so it can be asked before anyone has signed in.
  Future<DemoOffer> demoOffer() async => DemoOffer.fromJson(_data(await _client.get('/demo')));

  /// Enter the demo as [persona] (admin or user): a throw-away account, signed in on this device.
  Future<SignedIn> startDemo(String persona, {required String deviceName}) async =>
      _signedIn(await _client.post('/demo/$persona', body: {'device_name': deviceName}));

  Future<Account> me() async => Account.fromJson(_data(await _client.get('/me')));

  Future<void> logout() async => _client.post('/auth/logout');

  Future<void> logoutEverywhere() async => _client.post('/auth/logout-all');

  Future<List<PlanOffer>> plans() async {
    final json = await _client.get('/plans');

    return [for (final plan in json['data'] as List<dynamic>) PlanOffer.fromJson(plan as Map<String, dynamic>)];
  }

  Future<Dashboard> dashboard() async => Dashboard.fromJson(_data(await _client.get('/dashboard')));

  // -------------------------------------------------------------------- tools

  /// What the owner can set for this workspace, and whether this person may change it.
  Future<({List<Tool> tools, bool canEdit})> tools() async {
    final body = await _client.get('/settings/tools');
    final meta = body['meta'];

    return (
      tools: [for (final tool in (body['data'] as List<dynamic>? ?? const [])) Tool.fromJson(tool as Map<String, dynamic>)],
      canEdit: meta is Map && meta['can_edit'] == true,
    );
  }

  Future<Tool> saveTool(String key, Object? value) async => Tool.fromJson(_data(await _client.put('/settings/tools/${Uri.encodeComponent(key)}', body: {'value': value})));

  // ---------------------------------------------------------------- customers

  Future<Paged<Customer>> customers({String query = '', int page = 1}) async => Paged.fromJson(
        await _client.get('/customers', query: {if (query.trim().isNotEmpty) 'q': query.trim(), 'page': page}),
        Customer.fromJson,
      );

  Future<Customer> customer(String id) async => Customer.fromJson(_data(await _client.get('/customers/$id')));

  Future<Customer> createCustomer(CustomerForm form) async => Customer.fromJson(_data(await _client.post('/customers', body: form.toJson())));

  Future<Customer> updateCustomer(String id, CustomerForm form) async => Customer.fromJson(_data(await _client.put('/customers/$id', body: form.toJson())));

  Future<void> deleteCustomer(String id) async => _client.delete('/customers/$id');

  // ---------------------------------------------------------------- contracts

  /// [status]: active (the default), late, settled, cancelled or all.
  Future<Paged<Contract>> contracts({String status = 'active', String query = '', int page = 1}) async => Paged.fromJson(
        await _client.get('/contracts', query: {'status': status, if (query.trim().isNotEmpty) 'q': query.trim(), 'page': page}),
        Contract.fromJson,
      );

  Future<Contract> contract(String id) async => Contract.fromJson(_data(await _client.get('/contracts/$id')));

  Future<Contract> createContract(ContractForm form) async => Contract.fromJson(_data(await _client.post('/contracts', body: form.toJson())));

  Future<Contract> cancelContract(String id, {String? reason}) async =>
      Contract.fromJson(_data(await _client.post('/contracts/$id/cancel', body: {if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim()})));

  // ----------------------------------------------------------------- payments

  Future<Paged<LedgerLine>> payments({String? contractId, int page = 1}) async => Paged.fromJson(
        await _client.get('/payments', query: {'contract_id': ?contractId, 'page': page}),
        LedgerLine.fromJson,
      );

  /// Records a payment. Pass the SAME [idempotencyKey] when the same attempt is repeated.
  Future<LedgerLine> recordPayment(
    String contractId, {
    required Money amount,
    required String method,
    required String idempotencyKey,
    String? note,
    DateTime? paidOn,
  }) async {
    final json = await _client.post(
      '/contracts/$contractId/payments',
      headers: {'Idempotency-Key': idempotencyKey},
      body: {
        'amount': amount.toDecimalString(),
        'method': method,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        if (paidOn != null) 'paid_at': '${paidOn.year.toString().padLeft(4, '0')}-${paidOn.month.toString().padLeft(2, '0')}-${paidOn.day.toString().padLeft(2, '0')}',
      },
    );

    return LedgerLine.fromJson(_data(json));
  }

  Future<LedgerLine> voidPayment(String id, {String? reason}) async =>
      LedgerLine.fromJson(_data(await _client.post('/payments/$id/void', body: {if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim()})));
}

/// What a person fills in to add or change a customer.
class CustomerForm {
  const CustomerForm({
    required this.name,
    required this.phone,
    this.phoneSecondary = '',
    this.email = '',
    this.nationalId = '',
    this.address = '',
    this.notes = '',
    this.removeNationalId = false,
  });

  final String name;
  final String phone;
  final String phoneSecondary;
  final String email;
  final String nationalId;
  final String address;
  final String notes;
  final bool removeNationalId;

  Map<String, dynamic> toJson() => {
        'name': name.trim(),
        'phone': phone.trim(),
        'phone_secondary': phoneSecondary.trim(),
        'email': email.trim(),
        'national_id': nationalId.trim(),
        'address': address.trim(),
        'notes': notes.trim(),
        if (removeNationalId) 'remove_national_id': true,
      };
}

/// What a person fills in to open a contract.
class ContractForm {
  const ContractForm({
    required this.customerId,
    required this.type,
    required this.principal,
    this.downPayment = '',
    this.markupType = 'none',
    this.markupValue = '',
    this.installmentCount = 6,
    this.frequency = 'monthly',
    required this.startDate,
    this.firstDueDate = '',
    this.notes = '',
  });

  final String customerId;

  /// scheduled or cash.
  final String type;
  final String principal;
  final String downPayment;
  final String markupType;
  final String markupValue;
  final int installmentCount;
  final String frequency;
  final String startDate;
  final String firstDueDate;
  final String notes;

  Map<String, dynamic> toJson() => type == 'cash'
      ? {'customer_id': customerId, 'type': 'cash', 'principal': principal, 'start_date': startDate, if (notes.trim().isNotEmpty) 'notes': notes.trim()}
      : {
          'customer_id': customerId,
          'type': 'scheduled',
          'principal': principal,
          if (downPayment.isNotEmpty) 'down_payment': downPayment,
          'markup_type': markupType,
          if (markupValue.isNotEmpty) 'markup_value': markupValue,
          'installment_count': installmentCount,
          'frequency': frequency,
          'start_date': startDate,
          'first_due_date': firstDueDate,
          if (notes.trim().isNotEmpty) 'notes': notes.trim(),
        };
}
