import 'package:flutter/foundation.dart';

import '../core/money.dart';

// Plain immutable models of what the API returns. No code generation: each reads its own JSON. Amounts are read
// from the API's two-decimal strings into [Money]; nothing here ever touches a double for money.

Money _money(Object? value) => Money.parse((value ?? '0.00').toString());

Money? _moneyOrNull(Object? value) => value == null ? null : Money.parse(value.toString());

DateTime? _moment(Object? value) => value == null ? null : DateTime.parse(value.toString()).toLocal();

String? _text(Object? value) => value?.toString();

Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : const {};

List<Map<String, dynamic>> _list(Object? value) => value is List<dynamic> ? value.whereType<Map<String, dynamic>>().toList() : const [];

/// What a workspace may do with one feature right now. The app reads this and never decides by plan name.
@immutable
class Entitlement {
  const Entitlement({
    required this.type,
    required this.enabled,
    required this.limit,
    required this.used,
    required this.remaining,
    required this.unlimited,
  });

  factory Entitlement.fromJson(Map<String, dynamic> json) => Entitlement(
        type: (json['type'] ?? 'toggle').toString(),
        enabled: json['enabled'] == true,
        limit: (json['limit'] as num?)?.toInt(),
        used: (json['used'] as num?)?.toInt(),
        remaining: (json['remaining'] as num?)?.toInt(),
        unlimited: json['unlimited'] == true,
      );

  /// An entitlement that allows everything, for before the account is known.
  static const Entitlement open = Entitlement(type: 'toggle', enabled: true, limit: null, used: null, remaining: null, unlimited: true);

  /// toggle, limit or quota.
  final String type;
  final bool enabled;
  final int? limit;
  final int? used;
  final int? remaining;
  final bool unlimited;

  /// One more may be added.
  bool get allowsMore => enabled && (unlimited || limit == null || (used ?? 0) < limit!);

  /// Fraction of the allowance used, 0 to 1; null when there is no cap to measure against.
  double? get fraction => !enabled || limit == null || limit == 0 ? null : ((used ?? 0) / limit!).clamp(0, 1).toDouble();

  Map<String, dynamic> toJson() => {
        'type': type, 'enabled': enabled, 'limit': limit, 'used': used, 'remaining': remaining, 'unlimited': unlimited,
      };
}

@immutable
class Account {
  const Account({
    required this.userId,
    required this.name,
    required this.email,
    required this.emailVerified,
    required this.locale,
    required this.twoFactor,
    required this.tenantId,
    required this.businessName,
    required this.country,
    required this.currency,
    required this.role,
    required this.isTest,
    required this.isDemo,
    required this.planKey,
    required this.planName,
    required this.entitlements,
  });

  factory Account.fromJson(Map<String, dynamic> json) {
    final user = _map(json['user']);
    final tenant = _map(json['tenant']);
    final plan = _map(json['plan']);

    return Account(
      userId: user['id'].toString(),
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? '').toString(),
      emailVerified: user['email_verified'] == true,
      locale: (user['locale'] ?? 'en').toString(),
      twoFactor: user['two_factor'] == true,
      tenantId: tenant['id'].toString(),
      businessName: (tenant['name'] ?? '').toString(),
      country: (tenant['country'] ?? '').toString(),
      currency: (tenant['currency'] ?? 'USD').toString(),
      role: (tenant['role'] ?? 'viewer').toString(),
      isTest: tenant['is_test'] == true,
      isDemo: tenant['is_demo'] == true,
      planKey: (plan['key'] ?? 'free').toString(),
      planName: (plan['name'] ?? '').toString(),
      entitlements: {
        for (final entry in _map(json['entitlements']).entries) entry.key: Entitlement.fromJson(_map(entry.value)),
      },
    );
  }

  final String userId;
  final String name;
  final String email;
  final bool emailVerified;
  final String locale;
  final bool twoFactor;
  final String tenantId;
  final String businessName;
  final String country;
  final String currency;

  /// owner, manager, accountant, collector or viewer.
  final String role;

  /// An admin's sandbox with sample data: the app says so on every screen.
  final bool isTest;

  /// A throw-away workspace made by "Try the demo": the app says so and offers a real account.
  final bool isDemo;
  final String planKey;
  final String planName;
  final Map<String, Entitlement> entitlements;

  /// A feature the server did not mention is treated as allowed: the server still decides, and answers 402.
  Entitlement entitlement(String feature) => entitlements[feature] ?? Entitlement.open;

  bool get isFree => planKey == 'free';

  /// May add and change records (everyone but a viewer).
  bool get canWrite => role != 'viewer';

  /// May cancel contracts and void payments.
  bool get canDelete => role == 'owner' || role == 'manager';

  Map<String, dynamic> toJson() => {
        'user': {'id': userId, 'name': name, 'email': email, 'email_verified': emailVerified, 'locale': locale, 'two_factor': twoFactor},
        'tenant': {'id': tenantId, 'name': businessName, 'country': country, 'currency': currency, 'role': role, 'is_test': isTest, 'is_demo': isDemo},
        'plan': {'key': planKey, 'name': planName},
        'entitlements': {for (final e in entitlements.entries) e.key: e.value.toJson()},
      };
}

@immutable
class Installment {
  const Installment({
    required this.id,
    required this.number,
    required this.dueDate,
    required this.amount,
    required this.paidAmount,
    required this.remaining,
    required this.status,
    required this.state,
  });

  factory Installment.fromJson(Map<String, dynamic> json) => Installment(
        id: json['id'].toString(),
        number: (json['number'] as num).toInt(),
        dueDate: json['due_date'].toString(),
        amount: _money(json['amount']),
        paidAmount: _money(json['paid_amount']),
        remaining: _money(json['remaining']),
        status: (json['status'] ?? 'pending').toString(),
        state: (json['state'] ?? 'upcoming').toString(),
      );

  final String id;
  final int number;

  /// YYYY-MM-DD.
  final String dueDate;
  final Money amount;
  final Money paidAmount;
  final Money remaining;
  final String status;

  /// paid, overdue, partial or upcoming: what a person should see.
  final String state;
}

@immutable
class NextInstallment {
  const NextInstallment({required this.number, required this.dueDate, required this.remaining});

  final int number;
  final String dueDate;
  final Money remaining;
}

/// One line of the money ledger. Amounts are signed: a reversal is negative.
@immutable
class LedgerLine {
  const LedgerLine({
    required this.id,
    required this.type,
    required this.method,
    required this.amount,
    required this.paidAt,
    required this.note,
    required this.voided,
    required this.reversesId,
    required this.takenBy,
    required this.customerName,
    required this.contractId,
    required this.contractReference,
    required this.contractOwed,
    required this.contractStatus,
  });

  factory LedgerLine.fromJson(Map<String, dynamic> json) {
    final contract = _map(json['contract']);

    return LedgerLine(
      id: json['id'].toString(),
      type: (json['type'] ?? 'payment').toString(),
      method: (json['method'] ?? 'cash').toString(),
      amount: _money(json['amount']),
      paidAt: _moment(json['paid_at']) ?? DateTime.now(),
      note: _text(json['note']),
      voided: json['voided'] == true,
      reversesId: _text(json['reverses_transaction_id']),
      takenBy: _text(_map(json['created_by'])['name']),
      customerName: _text(_map(json['customer'])['name']),
      contractId: _text(contract['id']),
      contractReference: _text(contract['reference']),
      contractOwed: _moneyOrNull(contract['owed']),
      contractStatus: _text(contract['status']),
    );
  }

  final String id;

  /// payment, down_payment or reversal.
  final String type;
  final String method;
  final Money amount;
  final DateTime paidAt;
  final String? note;

  /// A payment that has since been reversed.
  final bool voided;
  final String? reversesId;
  final String? takenBy;
  final String? customerName;
  final String? contractId;
  final String? contractReference;

  /// What the contract owed right after this line, when the API says.
  final Money? contractOwed;
  final String? contractStatus;

  bool get isReversal => type == 'reversal';

  /// A payment that may still be voided.
  bool get canVoid => type == 'payment' && !voided;
}

@immutable
class Contract {
  const Contract({
    required this.id,
    required this.reference,
    required this.number,
    required this.type,
    required this.status,
    required this.state,
    required this.principal,
    required this.downPayment,
    required this.financed,
    required this.markupType,
    required this.markupValue,
    required this.markupAmount,
    required this.total,
    required this.installmentCount,
    required this.frequency,
    required this.startDate,
    required this.firstDueDate,
    required this.notes,
    required this.customerId,
    required this.customerName,
    required this.owed,
    required this.paid,
    required this.next,
    required this.installments,
    required this.transactions,
  });

  factory Contract.fromJson(Map<String, dynamic> json) {
    final customer = _map(json['customer']);
    final next = json['next_installment'];

    return Contract(
      id: json['id'].toString(),
      reference: json['reference'].toString(),
      number: (json['number'] as num).toInt(),
      type: (json['type'] ?? 'scheduled').toString(),
      status: (json['status'] ?? 'active').toString(),
      state: (json['state'] ?? json['status'] ?? 'active').toString(),
      principal: _money(json['principal']),
      downPayment: _money(json['down_payment']),
      financed: _money(json['financed']),
      markupType: (json['markup_type'] ?? 'none').toString(),
      markupValue: _money(json['markup_value']),
      markupAmount: _money(json['markup_amount']),
      total: _money(json['total']),
      installmentCount: (json['installment_count'] as num?)?.toInt() ?? 0,
      frequency: (json['frequency'] ?? 'monthly').toString(),
      startDate: json['start_date'].toString(),
      firstDueDate: json['first_due_date'].toString(),
      notes: _text(json['notes']),
      customerId: _text(customer['id']),
      customerName: _text(customer['name']),
      owed: _moneyOrNull(json['owed']),
      paid: _moneyOrNull(json['paid']),
      next: next is Map<String, dynamic>
          ? NextInstallment(number: (next['number'] as num).toInt(), dueDate: next['due_date'].toString(), remaining: _money(next['remaining']))
          : null,
      installments: _list(json['installments']).map(Installment.fromJson).toList(),
      transactions: _list(json['transactions']).map(LedgerLine.fromJson).toList(),
    );
  }

  final String id;

  /// C-0012.
  final String reference;
  final int number;

  /// scheduled or cash.
  final String type;

  /// active, settled or cancelled.
  final String status;

  /// status, or "late" for a running contract with an overdue instalment.
  final String state;
  final Money principal;
  final Money downPayment;
  final Money financed;
  final String markupType;
  final Money markupValue;
  final Money markupAmount;
  final Money total;
  final int installmentCount;
  final String frequency;
  final String startDate;
  final String firstDueDate;
  final String? notes;
  final String? customerId;
  final String? customerName;
  final Money? owed;
  final Money? paid;
  final NextInstallment? next;
  final List<Installment> installments;
  final List<LedgerLine> transactions;

  bool get isRunning => status == 'active';

  bool get isLate => state == 'late';

  /// More money may still be taken on it.
  bool get takesPayments => status != 'cancelled' && (owed == null || owed!.isPositive);
}

@immutable
class Customer {
  const Customer({
    required this.id,
    required this.name,
    required this.phone,
    required this.phoneSecondary,
    required this.email,
    required this.nationalId,
    required this.address,
    required this.notes,
    required this.owed,
    required this.runningContracts,
    required this.contracts,
  });

  factory Customer.fromJson(Map<String, dynamic> json) => Customer(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        phone: (json['phone'] ?? '').toString(),
        phoneSecondary: _text(json['phone_secondary']),
        email: _text(json['email']),
        nationalId: _text(json['national_id']),
        address: _text(json['address']),
        notes: _text(json['notes']),
        owed: _moneyOrNull(json['owed']),
        runningContracts: (json['running_contracts'] as num?)?.toInt(),
        contracts: _list(json['contracts']).map(Contract.fromJson).toList(),
      );

  final String id;
  final String name;
  final String phone;
  final String? phoneSecondary;
  final String? email;

  /// Only ever masked by the server: the last three digits.
  final String? nationalId;
  final String? address;
  final String? notes;
  final Money? owed;
  final int? runningContracts;
  final List<Contract> contracts;
}

@immutable
class DueToday {
  const DueToday({required this.contractId, required this.reference, required this.customerName, required this.amount, required this.dueDate});

  factory DueToday.fromJson(Map<String, dynamic> json) => DueToday(
        contractId: json['contract_id'].toString(),
        reference: (json['contract_reference'] ?? '').toString(),
        customerName: (json['customer_name'] ?? '').toString(),
        amount: _money(json['amount_due']),
        dueDate: (json['due_date'] ?? '').toString(),
      );

  final String contractId;
  final String reference;
  final String customerName;
  final Money amount;
  final String dueDate;
}

@immutable
class Dashboard {
  const Dashboard({
    required this.currency,
    required this.outstanding,
    required this.overdue,
    required this.collectedThisMonth,
    required this.activeCustomers,
    required this.collectionRate,
    required this.dueToday,
  });

  factory Dashboard.fromJson(Map<String, dynamic> json) => Dashboard(
        currency: (json['currency'] ?? 'USD').toString(),
        outstanding: _money(json['outstanding']),
        overdue: _money(json['overdue']),
        collectedThisMonth: _money(json['collected_this_month']),
        activeCustomers: (json['active_customers'] as num?)?.toInt() ?? 0,
        collectionRate: _text(json['collection_rate']),
        dueToday: _list(json['due_today']).map(DueToday.fromJson).toList(),
      );

  final String currency;
  final Money outstanding;
  final Money overdue;
  final Money collectedThisMonth;
  final int activeCustomers;

  /// Percent as the server computed it, or null when nothing falls due this month.
  final String? collectionRate;
  final List<DueToday> dueToday;
}

@immutable
class PlanFeature {
  const PlanFeature({required this.key, required this.type, required this.label, required this.enabled, required this.limit, required this.summary});

  final String key;
  final String type;
  final String label;
  final bool enabled;
  final int? limit;

  /// "Up to 5 customers", in the reader's language.
  final String summary;
}

@immutable
class PlanOffer {
  const PlanOffer({
    required this.key,
    required this.name,
    required this.description,
    required this.isFree,
    required this.currency,
    required this.monthlyPrice,
    required this.yearlyPrice,
    required this.yearlySavingPercent,
    required this.features,
  });

  factory PlanOffer.fromJson(Map<String, dynamic> json) => PlanOffer(
        key: json['key'].toString(),
        name: (json['name'] ?? '').toString(),
        description: _text(json['description']),
        isFree: json['is_free'] == true,
        currency: (json['currency'] ?? 'USD').toString(),
        monthlyPrice: _moneyOrNull(json['monthly_price']),
        yearlyPrice: _moneyOrNull(json['yearly_price']),
        yearlySavingPercent: (json['yearly_saving_percent'] as num?)?.toInt(),
        features: [
          for (final entry in _map(json['features']).entries)
            PlanFeature(
              key: entry.key,
              type: (_map(entry.value)['type'] ?? 'toggle').toString(),
              label: (_map(entry.value)['label'] ?? entry.key).toString(),
              enabled: _map(entry.value)['enabled'] == true,
              limit: (_map(entry.value)['limit'] as num?)?.toInt(),
              summary: (_map(entry.value)['summary'] ?? '').toString(),
            ),
        ],
      );

  final String key;
  final String name;
  final String? description;
  final bool isFree;
  final String currency;
  final Money? monthlyPrice;
  final Money? yearlyPrice;
  final int? yearlySavingPercent;
  final List<PlanFeature> features;
}

/// One page of a list, with where it sits in the whole.
@immutable
class Paged<T> {
  const Paged({required this.items, required this.page, required this.lastPage, required this.total});

  factory Paged.fromJson(Map<String, dynamic> json, T Function(Map<String, dynamic>) read) {
    final meta = _map(json['meta']);

    return Paged(
      items: _list(json['data']).map(read).toList(),
      page: (meta['current_page'] as num?)?.toInt() ?? 1,
      lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
      total: (meta['total'] as num?)?.toInt() ?? 0,
    );
  }

  final List<T> items;
  final int page;
  final int lastPage;
  final int total;

  bool get hasMore => page < lastPage;
}

/// One way into the demo: an admin with every feature, or a user with the Free plan's limits.
@immutable
class DemoPersona {
  const DemoPersona({required this.key, required this.label, required this.description, required this.plan});

  factory DemoPersona.fromJson(Map<String, dynamic> json) => DemoPersona(
        key: json['key'].toString(),
        label: (json['label'] ?? '').toString(),
        description: (json['description'] ?? '').toString(),
        plan: (json['plan'] ?? 'free').toString(),
      );

  /// admin or user.
  final String key;
  final String label;
  final String description;

  /// pro or free.
  final String plan;
}

/// Whether the server offers "Try the demo", and how.
@immutable
class DemoOffer {
  const DemoOffer({required this.enabled, required this.hours, required this.personas});

  factory DemoOffer.fromJson(Map<String, dynamic> json) => DemoOffer(
        enabled: json['enabled'] == true,
        hours: (json['hours'] as num?)?.toInt() ?? 0,
        personas: _list(json['personas']).map(DemoPersona.fromJson).toList(),
      );

  static const DemoOffer none = DemoOffer(enabled: false, hours: 0, personas: []);

  final bool enabled;
  final int hours;
  final List<DemoPersona> personas;
}

/// What signing in or registering returns.
@immutable
class SignedIn {
  const SignedIn({required this.token, required this.account});

  final String token;
  final Account account;
}
