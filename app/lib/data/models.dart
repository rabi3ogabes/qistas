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
    this.status = 'on',
    this.detail,
  });

  factory Entitlement.fromJson(Map<String, dynamic> json) => Entitlement(
        type: (json['type'] ?? 'toggle').toString(),
        enabled: json['enabled'] == true,
        // An older server sends no status: what it says is enabled is on, and nothing is hidden for want of one.
        status: (json['status'] ?? (json['enabled'] == false ? 'plan_locked' : 'on')).toString(),
        detail: json['detail']?.toString(),
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

  /// on, plan_locked (the plan lacks it: show it locked with a way to upgrade) or platform_off (the platform has it
  /// switched off: show nothing, there is nothing to buy).
  final String status;

  /// Why, when another feature is the reason (`dependency:<key>`).
  final String? detail;

  bool get isOn => status == 'on';

  bool get isPlatformOff => status == 'platform_off';
  final int? limit;
  final int? used;
  final int? remaining;
  final bool unlimited;

  /// One more may be added.
  bool get allowsMore => enabled && (unlimited || limit == null || (used ?? 0) < limit!);

  /// Fraction of the allowance used, 0 to 1; null when there is no cap to measure against.
  double? get fraction => !enabled || limit == null || limit == 0 ? null : ((used ?? 0) / limit!).clamp(0, 1).toDouble();

  Map<String, dynamic> toJson() => {
        'type': type, 'status': status, 'detail': detail, 'enabled': enabled, 'limit': limit, 'used': used, 'remaining': remaining, 'unlimited': unlimited,
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
    this.requireAppLock = false,
    this.deletionScheduledFor,
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
      requireAppLock: tenant['require_app_lock'] == true,
      deletionScheduledFor: tenant['deletion_scheduled_for']?.toString(),
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

  /// The business requires every phone to unlock Qistas (fingerprint, face or the phone's PIN) before showing its books.
  final bool requireAppLock;

  /// The owner asked to delete the business: it is read-only and erased after this day (YYYY-MM-DD) unless restored.
  final String? deletionScheduledFor;

  bool get isOwner => role == 'owner';
  final String planKey;
  final String planName;
  final Map<String, Entitlement> entitlements;

  /// A feature the server did not mention is treated as allowed: the server still decides, and answers 402.
  Entitlement entitlement(String feature) => entitlements[feature] ?? Entitlement.open;

  /// Whether to draw a feature at all: false only when the platform has switched it off. A feature the plan lacks is
  /// still drawn, locked, with the way to upgrade.
  bool shows(String feature) => !entitlement(feature).isPlatformOff;

  bool get isFree => planKey == 'free';

  /// May add and change records (everyone but a viewer).
  bool get canWrite => role != 'viewer';

  /// May cancel contracts and void payments.
  bool get canDelete => role == 'owner' || role == 'manager';

  /// May change the business's own rules (its tools, the app-lock requirement).
  bool get canManageSettings => role == 'owner' || role == 'manager';

  /// May take the whole of the books out (Win Plan PP10): the people who run the business and its accountant.
  bool get canExport => role == 'owner' || role == 'manager' || role == 'accountant';

  Map<String, dynamic> toJson() => {
        'user': {'id': userId, 'name': name, 'email': email, 'email_verified': emailVerified, 'locale': locale, 'two_factor': twoFactor},
        'tenant': {'id': tenantId, 'name': businessName, 'country': country, 'currency': currency, 'role': role, 'is_test': isTest, 'is_demo': isDemo, 'require_app_lock': requireAppLock, 'deletion_scheduled_for': deletionScheduledFor},
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
    this.tag,
    this.balanceAfter,
    this.recordedBy,
    this.recordedAt,
    this.reversalBy,
    this.reversalReason,
    this.reversalAt,
  });

  factory LedgerLine.fromJson(Map<String, dynamic> json) {
    final contract = _map(json['contract']);
    final reversal = _map(json['reversal']);

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
      tag: _text(json['tag']),
      balanceAfter: _moneyOrNull(json['balance_after']),
      recordedBy: _text(_map(json['recorded_by'])['name']) ?? _text(_map(json['created_by'])['name']),
      recordedAt: _moment(json['recorded_at']),
      reversalBy: _text(_map(reversal['by'])['name']),
      reversalReason: _text(reversal['reason']),
      reversalAt: _moment(reversal['at']),
    );
  }

  final String id;

  /// payment, down_payment or reversal (money in); charge or charge_reversal (what an open contract's customer took).
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

  /// advance, refund, early_discount or unpaid.
  final String? tag;

  /// On an open contract: the balance once this line was written. Null for lines from before it became open.
  final Money? balanceAfter;

  /// Who wrote the line and when (Win Plan PP16); [paidAt] is when the money moved.
  final String? recordedBy;
  final DateTime? recordedAt;

  /// On a voided line: who voided it, why and when.
  final String? reversalBy;
  final String? reversalReason;
  final DateTime? reversalAt;

  bool get isReversal => type == 'reversal' || type == 'charge_reversal';

  /// What the customer took on an open contract (or its reversal): it adds to what is owed.
  bool get isCharge => type == 'charge' || type == 'charge_reversal';

  /// A payment, or what an open contract's customer took, that may still be voided.
  bool get canVoid => (type == 'payment' || type == 'charge') && !voided;

  /// Money that came in has a receipt (Win Plan PP8); a void or a charge does not.
  bool get takesReceipt => type == 'payment' || type == 'down_payment';
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
    this.graceDays = 0,
    this.investorId,
    this.investorName,
    this.creditLimit,
    this.title,
    this.ownReference,
    this.costPrice,
    this.taxPercent,
    this.taxAmount,
    this.discountAmount,
    this.discountType = 'none',
    this.items = const [],
    this.archived = false,
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
      graceDays: (json['grace_days'] as num?)?.toInt() ?? 0,
      investorId: _text(_map(json['investor'])['id']),
      investorName: _text(_map(json['investor'])['name']),
      creditLimit: _moneyOrNull(json['credit_limit']),
      title: _text(json['title']),
      ownReference: _text(json['own_reference']),
      costPrice: _moneyOrNull(json['cost_price']),
      taxPercent: _text(json['tax_percent']),
      taxAmount: _moneyOrNull(json['tax_amount']),
      discountAmount: _moneyOrNull(json['discount_amount']),
      discountType: (json['discount_type'] ?? 'none').toString(),
      items: _list(json['items']).map(ContractItem.fromJson).toList(),
      archived: json['archived'] == true,
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

  /// Days after a due date before an instalment counts as late.
  final int graceDays;

  /// An open contract's limit, which warns and never refuses.
  final Money? creditLimit;

  /// What was sold, in a line, and the shop's own number (Win Plan PP7).
  final String? title;
  final String? ownReference;

  /// What it cost the shop; absent unless known.
  final Money? costPrice;

  /// The tax already in the price ("15.00"), and how much of it.
  final String? taxPercent;
  final Money? taxAmount;

  /// A discount at sale, taken off the price before the down payment.
  final Money? discountAmount;
  final String discountType;

  /// What was sold, item by item.
  final List<ContractItem> items;

  /// Put away by the shop once finished (Win Plan PP12): out of the lists, still in every report.
  final bool archived;

  /// What the customer pays for it less what it cost: null until the cost is known.
  Money? get margin => costPrice == null ? null : principal - (discountAmount ?? Money.zero) + markupAmount - costPrice!;

  /// Who funded it; absent for a collector, who does not see investors.
  final String? investorId;
  final String? investorName;
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

  /// 0 to 1: how much of what is to be collected has been. Null when the server did not say what was paid.
  double? get paidFraction {
    final paid = this.paid;
    if (paid == null || !total.isPositive) return null;

    return (paid.cents.toDouble() / total.cents.toDouble()).clamp(0.0, 1.0);
  }

  /// More money may still be taken on it.
  bool get takesPayments => status != 'cancelled' && (isOpen || owed == null || owed!.isPositive);

  /// A running tab with no schedule (Win Plan PP4): "they took" and "they paid", and the balance after each line.
  bool get isOpen => type == 'open';
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
    this.job,
    this.pinned = false,
    this.tags = const [],
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
        job: _text(json['job']),
        pinned: json['pinned'] == true,
        tags: _list(json['tags']).map(CustomerTag.fromJson).toList(),
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

  /// Where they work (Win Plan PP7).
  final String? job;
  final Money? owed;
  final int? runningContracts;
  final List<Contract> contracts;

  /// Kept at the top of every list (Win Plan PP12).
  final bool pinned;
  final List<CustomerTag> tags;
}

/// A label that groups customers, such as "Shop 2" or "Government staff" (Win Plan PP12).
@immutable
class CustomerTag {
  const CustomerTag({required this.id, required this.name, this.colour = 'grey', this.customers});

  factory CustomerTag.fromJson(Map<String, dynamic> json) => CustomerTag(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        colour: (json['colour'] ?? 'grey').toString(),
        customers: (json['customers'] as num?)?.toInt(),
      );

  /// The colours a tag can take, in the order they are offered.
  static const colours = ['grey', 'gold', 'green', 'blue', 'red', 'purple'];

  final String id;
  final String name;

  /// One of [colours].
  final String colour;

  /// How many customers carry it; only in the list of tags.
  final int? customers;
}

/// One instalment somebody still owes: what, who, since when or until when.
@immutable
class DueItem {
  const DueItem({
    required this.installmentId,
    required this.contractId,
    required this.reference,
    required this.customerId,
    required this.customerName,
    required this.phone,
    required this.amount,
    required this.dueDate,
    this.daysLate,
    this.daysUntil,
  });

  factory DueItem.fromJson(Map<String, dynamic> json) => DueItem(
        installmentId: (json['installment_id'] ?? '').toString(),
        contractId: json['contract_id'].toString(),
        reference: (json['contract_reference'] ?? '').toString(),
        customerId: (json['customer_id'] ?? '').toString(),
        customerName: (json['customer_name'] ?? '').toString(),
        phone: (json['customer_phone'] ?? '').toString(),
        amount: _money(json['amount_due']),
        dueDate: (json['due_date'] ?? '').toString(),
        daysLate: (json['days_late'] as num?)?.toInt(),
        daysUntil: (json['days_until'] as num?)?.toInt(),
      );

  final String installmentId;
  final String contractId;

  /// C-0012.
  final String reference;
  final String customerId;
  final String customerName;

  /// As the customer was entered; empty when none was given.
  final String phone;

  /// What is still owed on this instalment.
  final Money amount;

  /// YYYY-MM-DD.
  final String dueDate;

  /// Whole days past the due date; only on the overdue list.
  final int? daysLate;

  /// Whole days to go; only on the coming-up list.
  final int? daysUntil;

  bool get isOverdue => (daysLate ?? 0) > 0;

  bool get hasPhone => phone.replaceAll(RegExp(r'[^\d]'), '').length >= 7;
}

/// What was taken on one day, for the small chart.
@immutable
class DayTotal {
  const DayTotal(this.date, this.amount);

  /// YYYY-MM-DD.
  final String date;
  final Money amount;
}

@immutable
class Dashboard {
  const Dashboard({
    required this.currency,
    required this.outstanding,
    required this.overdue,
    required this.collectedThisMonth,
    required this.collectedLastMonth,
    required this.expectedThisMonth,
    required this.activeCustomers,
    required this.collectionRate,
    required this.dueToday,
    required this.overdueList,
    required this.upcoming,
    required this.daily,
  });

  factory Dashboard.fromJson(Map<String, dynamic> json) => Dashboard(
        currency: (json['currency'] ?? 'USD').toString(),
        outstanding: _money(json['outstanding']),
        overdue: _money(json['overdue']),
        collectedThisMonth: _money(json['collected_this_month']),
        collectedLastMonth: _money(json['collected_last_month']),
        expectedThisMonth: _money(json['expected_this_month']),
        activeCustomers: (json['active_customers'] as num?)?.toInt() ?? 0,
        collectionRate: _text(json['collection_rate']),
        dueToday: _list(json['due_today']).map(DueItem.fromJson).toList(),
        overdueList: _list(json['overdue_list']).map(DueItem.fromJson).toList(),
        upcoming: _list(json['upcoming']).map(DueItem.fromJson).toList(),
        daily: [for (final day in _list(json['daily_collected'])) DayTotal(day['date'].toString(), _money(day['amount']))],
      );

  final String currency;
  final Money outstanding;
  final Money overdue;
  final Money collectedThisMonth;
  final Money collectedLastMonth;

  /// What falls due this calendar month, paid or not.
  final Money expectedThisMonth;
  final int activeCustomers;

  /// Percent as the server computed it, or null when nothing falls due this month.
  final String? collectionRate;

  /// The share of this month's instalments already paid, 0 to 1, or null when none fall due.
  double? get collectionFraction {
    final rate = double.tryParse(collectionRate ?? '');

    return rate == null ? null : (rate / 100).clamp(0, 1).toDouble();
  }

  final List<DueItem> dueToday;

  /// Oldest first.
  final List<DueItem> overdueList;

  /// Soonest first, the next seven days.
  final List<DueItem> upcoming;

  /// The last fourteen days, oldest first.
  final List<DayTotal> daily;

  /// Everything that wants a nudge or a payment today: late first, then due today.
  List<DueItem> get needsYou => [...overdueList, ...dueToday];

  /// This month has already taken as much as the whole of last month.
  bool get beatLastMonth => collectedLastMonth.isPositive && collectedThisMonth >= collectedLastMonth;

  /// How much more this month must take to match last month; zero when it already has (or last month took nothing).
  Money get toMatchLastMonth => beatLastMonth || !collectedLastMonth.isPositive ? Money.zero : collectedLastMonth - collectedThisMonth;
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

/// One thing the owner can set for the workspace, described by the server so that a new tool needs no new screen:
/// a switch, a whole number or one of a few choices.
@immutable
class Tool {
  const Tool({required this.key, required this.type, required this.label, required this.help, required this.value, this.options = const []});

  factory Tool.fromJson(Map<String, dynamic> json) => Tool(
        key: json['key'].toString(),
        type: (json['type'] ?? 'switch').toString(),
        label: (json['label'] ?? '').toString(),
        help: (json['help'] ?? '').toString(),
        value: json['value'],
        options: [
          for (final option in (json['options'] as List<dynamic>? ?? const [])) (value: _map(option)['value'].toString(), label: _map(option)['label'].toString()),
        ],
      );

  final String key;

  /// switch, int or select.
  final String type;
  final String label;
  final String help;
  final Object? value;

  /// For a select: what may be chosen.
  final List<({String value, String label})> options;
}

/// A person in the business, with what they may do there.
@immutable
class TeamMember {
  const TeamMember({required this.id, required this.name, required this.email, required this.role});

  factory TeamMember.fromJson(Map<String, dynamic> json) => TeamMember(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        email: (json['email'] ?? '').toString(),
        role: (json['role'] ?? 'viewer').toString(),
      );

  final String id;
  final String name;
  final String email;

  /// owner, manager, accountant, collector or viewer.
  final String role;
}

/// An invitation link still waiting to be used. The link itself is shown only once, when it is made.
@immutable
class TeamInvitation {
  const TeamInvitation({required this.id, required this.role, this.name, this.phone, required this.expiresAt});

  factory TeamInvitation.fromJson(Map<String, dynamic> json) => TeamInvitation(
        id: json['id'].toString(),
        role: (json['role'] ?? 'viewer').toString(),
        name: json['name']?.toString(),
        phone: json['phone']?.toString(),
        expiresAt: DateTime.tryParse((json['expires_at'] ?? '').toString())?.toLocal() ?? DateTime.now(),
      );

  final String id;
  final String role;
  final String? name;
  final String? phone;
  final DateTime expiresAt;
}

/// The team page: the people, the invitations waiting, and what the reader may change.
@immutable
class Team {
  const Team({required this.members, required this.invitations, required this.canManage, required this.assignableRoles, this.limit, required this.used});

  factory Team.fromJson(Map<String, dynamic> json) => Team(
        members: [for (final m in (json['members'] as List<dynamic>? ?? const [])) TeamMember.fromJson(m as Map<String, dynamic>)],
        invitations: [for (final i in (json['invitations'] as List<dynamic>? ?? const [])) TeamInvitation.fromJson(i as Map<String, dynamic>)],
        canManage: json['can_manage'] == true,
        assignableRoles: [for (final r in (json['assignable_roles'] as List<dynamic>? ?? const [])) r.toString()],
        limit: (json['limit'] as num?)?.toInt(),
        used: (json['used'] as num?)?.toInt() ?? 0,
      );

  final List<TeamMember> members;
  final List<TeamInvitation> invitations;
  final bool canManage;
  final List<String> assignableRoles;

  /// People the plan allows (members plus invitations waiting); null is unlimited.
  final int? limit;
  final int used;

  bool get isFull => limit != null && used >= limit!;
}

/// A phone or browser signed in to my account (Win Plan PP16): each sign-in is its own token, named after the device.
@immutable
class Device {
  const Device({required this.id, required this.name, required this.current, this.lastUsedAt, this.lastUsedIp, this.signedInAt});

  factory Device.fromJson(Map<String, dynamic> json) => Device(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        current: json['current'] == true,
        lastUsedAt: _moment(json['last_used_at']),
        lastUsedIp: _text(json['last_used_ip']),
        signedInAt: _moment(json['signed_in_at']),
      );

  final String id;
  final String name;

  /// This very device.
  final bool current;
  final DateTime? lastUsedAt;
  final String? lastUsedIp;
  final DateTime? signedInAt;
}

/// One thing a contract sold (Win Plan PP7).
@immutable
class ContractItem {
  const ContractItem({required this.name, required this.quantity, this.serial, this.cost, this.price});

  factory ContractItem.fromJson(Map<String, dynamic> json) => ContractItem(
        name: (json['name'] ?? '').toString(),
        quantity: (json['quantity'] as num?)?.toInt() ?? 1,
        serial: _text(json['serial']),
        cost: _moneyOrNull(json['cost']),
        price: _moneyOrNull(json['price']),
      );

  final String name;
  final int quantity;

  /// A serial number, or a phone's IMEI.
  final String? serial;
  final Money? cost;
  final Money? price;
}

/// Something the shop sells, to pick from when opening a contract (Win Plan PP7). No stock is counted.
@immutable
class Product {
  const Product({required this.id, required this.name, required this.archived, this.sku, this.defaultPrice, this.cost});

  factory Product.fromJson(Map<String, dynamic> json) => Product(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        archived: json['archived'] == true,
        sku: _text(json['sku']),
        defaultPrice: _moneyOrNull(json['default_price']),
        cost: _moneyOrNull(json['cost']),
      );

  final String id;
  final String name;
  final bool archived;
  final String? sku;
  final Money? defaultPrice;
  final Money? cost;
}
