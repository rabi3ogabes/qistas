import 'dart:math';
import 'dart:typed_data';

import '../core/api/api_client.dart';
import '../core/money.dart';
import '../domain/schedule_generator.dart';
import 'backups.dart';
import 'documents.dart';
import 'investors.dart';
import 'models.dart';
import 'notifications.dart';

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

  /// The look to wear (see LookController). [etag] is the tag of the look the app has: 304 means it is still current.
  Future<({int status, Map<String, dynamic>? data, String? etag})> appearance({String? etag}) async {
    final answer = await _client.getConditional('/appearance', etag: etag);
    final data = answer.body['data'];

    return (status: answer.status, data: data is Map<String, dynamic> ? data : null, etag: answer.etag);
  }

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

  // ----------------------------------------------------------------- products

  Future<List<Product>> products({bool archived = false}) async {
    final json = await _client.get('/products', query: {if (archived) 'archived': '1'});

    return [for (final item in (json['data'] as List<dynamic>? ?? const [])) Product.fromJson(item as Map<String, dynamic>)];
  }

  Future<Product> createProduct({required String name, String? defaultPrice, String? cost, String? sku}) async => Product.fromJson(_data(await _client.post('/products', body: {
        'name': name.trim(),
        'default_price': ?defaultPrice,
        'cost': ?cost,
        if (sku != null && sku.trim().isNotEmpty) 'sku': sku.trim(),
      })));

  Future<Product> archiveProduct(String id, {bool archived = true}) async => Product.fromJson(_data(await _client.put('/products/$id', body: {'archived': archived})));

  // ------------------------------------------------------------------ devices

  Future<List<Device>> devices() async {
    final json = await _client.get('/devices');

    return [for (final item in (json['data'] as List<dynamic>? ?? const [])) Device.fromJson(item as Map<String, dynamic>)];
  }

  Future<void> signOutDevice(String id) async => _client.delete('/devices/$id');

  // ---------------------------------------------------------------- alerts and reminders (Win Plan PP9)

  /// Tells the server this phone takes pushes. Asking again with the same token only refreshes it.
  Future<void> registerPushToken(String token, {required String platform, String? appVersion}) async =>
      _client.post('/push-tokens', body: {'token': token, 'platform': platform, 'app_version': ?appVersion});

  /// Stops pushes to this phone (on signing out).
  Future<void> deletePushToken(String token) async => _client.delete('/push-tokens/${Uri.encodeComponent(token)}');

  Future<InboxPage> inbox() async => InboxPage.fromJson(await _client.get('/notifications'));

  /// Marks the given entries read, or all of them; returns how many are still unread.
  Future<int> markRead([List<String>? ids]) async =>
      ((_data(await _client.post('/notifications/read', body: {'ids': ?ids})))['unread'] as num?)?.toInt() ?? 0;

  Future<AlertPreferences> alertPreferences() async => AlertPreferences.fromJson(await _client.get('/notifications/preferences'));

  Future<AlertPreferences> saveAlertPreferences(AlertPreferences preferences) async =>
      AlertPreferences.fromJson(await _client.put('/notifications/preferences', body: preferences.toJson()));

  /// Who pays today ([scope] due) or who is late ([scope] late), each with the message ready in [language].
  Future<List<DueReminder>> reminders({String scope = 'due', required String language}) async {
    final json = await _client.get('/reminders/due-today', query: {'scope': scope, 'language': language});

    return [for (final row in (json['data'] as List<dynamic>? ?? const [])) DueReminder.fromJson(row as Map<String, dynamic>)];
  }

  Future<List<ReminderWording>> reminderWording() async {
    final json = await _client.get('/message-templates');

    return [for (final row in (json['data'] as List<dynamic>? ?? const [])) ReminderWording.fromJson(row as Map<String, dynamic>)];
  }

  /// Saves the business's own words for [key] in [language]; empty words bring the default back.
  Future<ReminderWording> saveReminderWording(String key, {required String language, required String body}) async =>
      ReminderWording.fromJson(_data(await _client.put('/message-templates/$key', body: {'language': language, 'body': body})));

  // ---------------------------------------------------------------- backups and the activity log (Win Plan PP10)

  Future<BackupsOverview> backups() async => BackupsOverview.fromJson(await _client.get('/backups'));

  /// Everything in one file ([format] xlsx or csv). Usually ready at once; a pending one is asked for again.
  Future<WorkspaceExport> createExport(String format) async => WorkspaceExport.fromJson(_data(await _client.post('/exports', body: {'format': format})));

  Future<WorkspaceExport> workspaceExport(String id) async => WorkspaceExport.fromJson(_data(await _client.get('/exports/$id')));

  /// The file behind an export, through its five-minute link: its name and its bytes.
  Future<({String filename, Uint8List bytes})> downloadExport(String id) async {
    final link = _data(await _client.get('/exports/$id/download'));

    return (filename: link['filename'] as String? ?? 'qistas.xlsx', bytes: await _client.getBytes(link['url'] as String));
  }

  Future<ActivityPage> activity({String? user, String? kind, int page = 1}) async =>
      ActivityPage.fromJson(await _client.get('/activity', query: {'user': ?user, 'kind': ?kind, 'page': page}));

  // ---------------------------------------------------------------- documents (Win Plan PP8)

  /// A statement, report or receipt as PDF bytes.
  Future<Uint8List> document(String path, Map<String, dynamic> query) => _client.getBytes(path, query: query);

  Future<({BusinessProfile profile, bool canEdit})> businessProfile() async {
    final json = await _client.get('/settings/business-profile');
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};

    return (profile: BusinessProfile.fromJson(_data(json)), canEdit: meta['can_edit'] == true);
  }

  /// Saves the business profile's words: a field sent empty is cleared.
  Future<BusinessProfile> saveBusinessProfile(Map<String, String> fields) async =>
      BusinessProfile.fromJson(_data(await _client.put('/settings/business-profile', body: {for (final entry in fields.entries) entry.key: entry.value.trim()})));

  /// A new logo or signature ([slot] is `logo` or `signature`).
  Future<BusinessProfile> uploadBusinessPicture(String slot, List<int> bytes, String filename) async =>
      BusinessProfile.fromJson(_data(await _client.upload('/settings/business-profile/$slot', field: 'file', bytes: bytes, filename: filename)));

  Future<BusinessProfile> removeBusinessPicture(String slot) async => BusinessProfile.fromJson(_data(await _client.delete('/settings/business-profile/$slot')));

  Future<DocumentPreferences> documentPreferences() async => DocumentPreferences.fromJson(_data(await _client.get('/settings/documents')));

  Future<DocumentPreferences> saveDocumentPreferences(DocumentPreferences preferences) async =>
      DocumentPreferences.fromJson(_data(await _client.put('/settings/documents', body: preferences.toJson())));

  // ---------------------------------------------------------------- investors

  Future<InvestorsPage> investors() async => InvestorsPage.fromJson(_data(await _client.get('/investors')));

  Future<InvestorDetail> investor(String id) async => InvestorDetail.fromJson(_data(await _client.get('/investors/$id')));

  Future<Investor> createInvestor({required String name, String? commercialRegistration, String? commissionPercent, String? openingCapital, String? notes}) async =>
      Investor.fromJson(_data(await _client.post('/investors', body: {
        'name': name.trim(),
        if (commercialRegistration != null && commercialRegistration.trim().isNotEmpty) 'commercial_registration': commercialRegistration.trim(),
        if (commissionPercent != null && commissionPercent.trim().isNotEmpty) 'commission_percent': commissionPercent.trim(),
        if (openingCapital != null && openingCapital.trim().isNotEmpty) 'opening_capital': openingCapital.trim(),
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      })));

  Future<Investor> updateInvestor(String id, {required String name, String? commercialRegistration, String? commissionPercent, String? notes, bool? archived}) async =>
      Investor.fromJson(_data(await _client.put('/investors/$id', body: {
        'name': name.trim(),
        'commercial_registration': commercialRegistration?.trim() ?? '',
        'commission_percent': ?commissionPercent?.trim(),
        'notes': notes?.trim() ?? '',
        'archived': ?archived,
      })));

  /// Money put in (deposit) or taken out (withdrawal); [amount] is positive either way.
  Future<InvestorEntry> recordInvestorEntry(String investorId, {required String type, required String amount, String? occurredOn, String? note}) async =>
      InvestorEntry.fromJson(_data(await _client.post('/investors/$investorId/entries', body: {
        'type': type,
        'amount': amount,
        'occurred_on': ?occurredOn,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
      })));

  Future<InvestorEntry> reverseInvestorEntry(String entryId) async => InvestorEntry.fromJson(_data(await _client.post('/investor-entries/$entryId/reverse')));

  // --------------------------------------------------------------------- team

  Future<Team> team() async => Team.fromJson(_data(await _client.get('/team')));

  /// Makes an invitation link for [role]. The link is in this answer only (the server keeps a fingerprint of it).
  Future<({TeamInvitation invitation, String url})> invite({required String role, String? name, String? phone}) async {
    final data = _data(await _client.post('/team/invitations', body: {
      'role': role,
      if (name != null && name.trim().isNotEmpty) 'name': name.trim(),
      if (phone != null && phone.trim().isNotEmpty) 'phone': phone.trim(),
    }));

    return (invitation: TeamInvitation.fromJson(data['invitation'] as Map<String, dynamic>), url: data['url'].toString());
  }

  Future<void> withdrawInvitation(String id) async => _client.delete('/team/invitations/$id');

  Future<TeamMember> changeRole(String memberId, String role) async =>
      TeamMember.fromJson(_data(await _client.put('/team/members/$memberId', body: {'role': role})));

  Future<void> removeMember(String memberId) async => _client.delete('/team/members/$memberId');

  /// Delete my account. The owner deletes the business (read-only, erased after `restoreUntil` unless restored);
  /// anyone else deletes only their own login, at once.
  Future<({String scope, String? restoreUntil})> deleteAccount({required String password, String? code, String? confirmName}) async {
    final data = _data(await _client.post('/account/deletion', body: {
      'password': password,
      if (code != null && code.isNotEmpty) 'code': code,
      'confirm_name': ?confirmName,
    }));

    return (scope: (data['scope'] ?? 'login').toString(), restoreUntil: data['restore_until']?.toString());
  }

  /// The owner restores a business they asked to delete.
  Future<void> restoreAccount() async => _client.delete('/account/deletion');

  /// Whether every phone in the business must unlock Qistas first. Owner and managers only; answers the rule as saved.
  Future<bool> setAppLockRequired(bool required) async =>
      _data(await _client.put('/workspace/security', body: {'require_app_lock': required}))['require_app_lock'] == true;

  // ---------------------------------------------------------------- customers

  /// [sort]: name (the default), balance, next_due or activity; pinned customers always come first. [tag] keeps only the
  /// customers carrying it.
  Future<Paged<Customer>> customers({String query = '', int page = 1, String sort = 'name', String? tag}) async => Paged.fromJson(
        await _client.get('/customers', query: {
          if (query.trim().isNotEmpty) 'q': query.trim(),
          if (sort != 'name') 'sort': sort,
          'tag': ?tag,
          'page': page,
        }),
        Customer.fromJson,
      );

  /// Keeps a customer at the top of every list, or lets them go back to their place.
  Future<Customer> pinCustomer(String id, {bool pinned = true}) async =>
      Customer.fromJson(_data(pinned ? await _client.post('/customers/$id/pin') : await _client.delete('/customers/$id/pin')));

  // ---------------------------------------------------------------- tags

  /// The business's tags, by name, with how many customers carry each.
  Future<List<CustomerTag>> tags() async {
    final json = await _client.get('/tags');

    return [for (final item in (json['data'] as List<dynamic>? ?? const [])) CustomerTag.fromJson(item as Map<String, dynamic>)];
  }

  Future<CustomerTag> createTag({required String name, String colour = 'grey'}) async =>
      CustomerTag.fromJson(_data(await _client.post('/tags', body: {'name': name.trim(), 'colour': colour})));

  Future<CustomerTag> updateTag(String id, {required String name, required String colour}) async =>
      CustomerTag.fromJson(_data(await _client.put('/tags/$id', body: {'name': name.trim(), 'colour': colour})));

  /// Lets go of a tag; the customers who carried it are unchanged otherwise.
  Future<void> deleteTag(String id) async => _client.delete('/tags/$id');

  Future<Customer> customer(String id) async => Customer.fromJson(_data(await _client.get('/customers/$id')));

  Future<Customer> createCustomer(CustomerForm form) async => Customer.fromJson(_data(await _client.post('/customers', body: form.toJson())));

  Future<Customer> updateCustomer(String id, CustomerForm form) async => Customer.fromJson(_data(await _client.put('/customers/$id', body: form.toJson())));

  Future<void> deleteCustomer(String id) async => _client.delete('/customers/$id');

  // ---------------------------------------------------------------- contracts

  /// [status]: active (the default), late, settled, cancelled, all, or archived (the only view archived contracts show in).
  Future<Paged<Contract>> contracts({String status = 'active', String query = '', int page = 1}) async => Paged.fromJson(
        await _client.get('/contracts', query: {'status': status, if (query.trim().isNotEmpty) 'q': query.trim(), 'page': page}),
        Contract.fromJson,
      );

  Future<Contract> contract(String id) async => Contract.fromJson(_data(await _client.get('/contracts/$id')));

  /// Opens a contract. The server may add warnings that never stop the sale, such as a serial already on another
  /// running contract.
  Future<({Contract contract, List<String> warnings})> createContract(ContractForm form) async {
    final json = await _client.post('/contracts', body: form.toJson());
    final warnings = ((json['meta'] as Map<String, dynamic>?)?['warnings'] as List<dynamic>? ?? const [])
        .map((w) => (w as Map<String, dynamic>)['message'] as String)
        .toList();

    return (contract: Contract.fromJson(_data(json)), warnings: warnings);
  }

  Future<Contract> cancelContract(String id, {String? reason}) async =>
      Contract.fromJson(_data(await _client.post('/contracts/$id/cancel', body: {if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim()})));

  /// Puts a settled or cancelled contract away, out of the lists, or brings it back (Win Plan PP12).
  Future<Contract> archiveContract(String id, {bool archived = true}) async =>
      Contract.fromJson(_data(await _client.post('/contracts/$id/${archived ? 'archive' : 'unarchive'}')));

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
    String? tag,
  }) async {
    final json = await _client.post(
      '/contracts/$contractId/payments',
      headers: {'Idempotency-Key': idempotencyKey},
      body: {
        'amount': amount.toDecimalString(),
        'method': method,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        'tag': ?tag,
        if (paidOn != null) 'paid_at': '${paidOn.year.toString().padLeft(4, '0')}-${paidOn.month.toString().padLeft(2, '0')}-${paidOn.day.toString().padLeft(2, '0')}',
      },
    );

    return LedgerLine.fromJson(_data(json));
  }

  /// "They took" on an open contract. Pass the SAME [idempotencyKey] when the same attempt is repeated.
  Future<({LedgerLine line, Money balance, bool overCreditLimit})> recordCharge(
    String contractId, {
    required Money amount,
    required String idempotencyKey,
    String? tag,
    String? note,
  }) async {
    final json = await _client.post(
      '/contracts/$contractId/charges',
      headers: {'Idempotency-Key': idempotencyKey},
      body: {'amount': amount.toDecimalString(), 'tag': ?tag, if (note != null && note.trim().isNotEmpty) 'note': note.trim()},
    );
    final meta = json['meta'] is Map<String, dynamic> ? json['meta'] as Map<String, dynamic> : const <String, dynamic>{};

    return (
      line: LedgerLine.fromJson(_data(json)),
      balance: Money.parse((meta['balance'] ?? '0.00').toString()),
      overCreditLimit: meta['over_credit_limit'] == true,
    );
  }

  /// What turning a scheduled or cash contract into an open one would do; nothing changes.
  Future<({int superseded, Money openingBalance})> previewConvertToOpen(String contractId) async {
    final data = _data(await _client.post('/contracts/$contractId/convert-to-open', query: {'preview': '1'}));

    return (superseded: (data['superseded'] as num).toInt(), openingBalance: Money.parse(data['opening_balance'].toString()));
  }

  Future<Contract> convertToOpen(String contractId) async =>
      Contract.fromJson((_data(await _client.post('/contracts/$contractId/convert-to-open')))['contract'] as Map<String, dynamic>);

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
    this.job = '',
    this.tags,
  });

  final String name;
  final String phone;
  final String phoneSecondary;
  final String email;
  final String nationalId;
  final String address;
  final String notes;
  final bool removeNationalId;

  /// Where they work (Win Plan PP7).
  final String job;

  /// The tag ids they carry (Win Plan PP12). Null leaves their tags as they are; empty takes them all off.
  final List<String>? tags;

  Map<String, dynamic> toJson() => {
        'name': name.trim(),
        'phone': phone.trim(),
        'phone_secondary': phoneSecondary.trim(),
        'email': email.trim(),
        'national_id': nationalId.trim(),
        'address': address.trim(),
        'notes': notes.trim(),
        'job': job.trim(),
        'tags': ?tags,
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
    this.graceDays = 0,
    this.customSchedule = const [],
    this.investorId,
    this.openingBalance = '',
    this.creditLimit = '',
    this.title = '',
    this.ownReference = '',
    this.costPrice = '',
    this.taxPercent = '',
    this.discountType = 'none',
    this.discountValue = '',
    this.items = const [],
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

  /// Days after a due date before an instalment counts as late.
  final int graceDays;

  /// The shop's own dates and amounts, when [frequency] is custom: the count and the first date then come from them.
  final List<ScheduleEntry> customSchedule;

  /// Who funds it; the business's own capital when null.
  final String? investorId;

  /// An open contract: what the customer owes opening it, and how far the tab may grow before the app warns.
  final String openingBalance;
  final String creditLimit;

  /// Contract details (Win Plan PP7) and a discount at sale (PP6); empty means not given.
  final String title;
  final String ownReference;
  final String costPrice;
  final String taxPercent;
  final String discountType;
  final String discountValue;
  final List<ContractItemForm> items;

  Map<String, dynamic> get _details => {
        if (title.trim().isNotEmpty) 'title': title.trim(),
        if (ownReference.trim().isNotEmpty) 'own_reference': ownReference.trim(),
        if (costPrice.isNotEmpty) 'cost_price': costPrice,
        if (taxPercent.isNotEmpty) 'tax_percent': taxPercent,
        if (discountType != 'none' && discountValue.isNotEmpty) ...{'discount_type': discountType, 'discount_value': discountValue},
        if (items.isNotEmpty) 'items': [for (final item in items) item.toJson()],
      };

  bool get _custom => frequency == ScheduleGenerator.custom;

  Map<String, dynamic> toJson() => type == 'open'
      ? {
          'customer_id': customerId,
          'type': 'open',
          'start_date': startDate,
          if (openingBalance.isNotEmpty) 'opening_balance': openingBalance,
          if (creditLimit.isNotEmpty) 'credit_limit': creditLimit,
          if (notes.trim().isNotEmpty) 'notes': notes.trim(),
          'investor_id': ?investorId,
        }
      : type == 'cash'
      ? {
          'customer_id': customerId,
          'type': 'cash',
          'principal': principal,
          'start_date': startDate,
          if (notes.trim().isNotEmpty) 'notes': notes.trim(),
          if (investorId != null) 'investor_id': investorId,
          ..._details,
        }
      : {
          'customer_id': customerId,
          'type': 'scheduled',
          'principal': principal,
          if (downPayment.isNotEmpty) 'down_payment': downPayment,
          'markup_type': markupType,
          if (markupValue.isNotEmpty) 'markup_value': markupValue,
          if (!_custom) 'installment_count': installmentCount,
          'frequency': frequency,
          'start_date': startDate,
          if (!_custom) 'first_due_date': firstDueDate,
          if (_custom) 'custom_schedule': [for (final entry in customSchedule) entry.toJson()],
          if (graceDays > 0) 'grace_days': graceDays,
          if (investorId != null) 'investor_id': investorId,
          ..._details,
          if (notes.trim().isNotEmpty) 'notes': notes.trim(),
        };
}

/// One thing being sold, as typed in the contract form.
class ContractItemForm {
  const ContractItemForm({required this.name, this.quantity = 1, this.serial = '', this.price = '', this.cost = '', this.productId});

  final String name;
  final int quantity;
  final String serial;
  final String price;
  final String cost;
  final String? productId;

  Map<String, dynamic> toJson() => {
        'name': name.trim(),
        'quantity': quantity,
        if (serial.trim().isNotEmpty) 'serial': serial.trim(),
        if (price.isNotEmpty) 'price': price,
        if (cost.isNotEmpty) 'cost': cost,
        'product_id': ?productId,
      };
}
