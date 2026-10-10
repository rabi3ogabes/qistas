import 'package:flutter/foundation.dart';

import '../core/money.dart';

Money _money(Object? value) => Money.parse((value ?? '0.00').toString());

Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : const {};

List<Map<String, dynamic>> _list(Object? value) => value is List<dynamic> ? value.whereType<Map<String, dynamic>>().toList() : const [];

/// An investor's figures (Win Plan PP3), all worked out by the server from their ledger.
@immutable
class InvestorSummary {
  const InvestorSummary({
    required this.wallet,
    required this.outInContracts,
    required this.profitEarned,
    required this.profitExpected,
    required this.customers,
    required this.contracts,
  });

  factory InvestorSummary.fromJson(Map<String, dynamic> json) => InvestorSummary(
        wallet: _money(json['wallet']),
        outInContracts: _money(json['out_in_contracts']),
        profitEarned: _money(json['profit_earned']),
        profitExpected: _money(json['profit_expected']),
        customers: (json['customers'] as num?)?.toInt() ?? 0,
        contracts: (json['contracts'] as num?)?.toInt() ?? 0,
      );

  /// What they hold: everything in, less everything out and everything funding contracts.
  final Money wallet;

  /// Principal funded that customers have not paid back yet.
  final Money outInContracts;

  /// Profit credited as customers paid, with commissions passed on or received.
  final Money profitEarned;

  /// Markup of running contracts not paid yet.
  final Money profitExpected;
  final int customers;
  final int contracts;
}

/// Someone whose money funds contracts: the business's own capital (the main investor) or a partner.
@immutable
class Investor {
  const Investor({
    required this.id,
    required this.name,
    required this.isMain,
    required this.currency,
    required this.commissionPercent,
    required this.archived,
    required this.summary,
    this.commercialRegistration,
    this.notes,
  });

  factory Investor.fromJson(Map<String, dynamic> json) => Investor(
        id: json['id'].toString(),
        name: (json['name'] ?? '').toString(),
        isMain: json['is_main'] == true,
        currency: (json['currency'] ?? '').toString(),
        commissionPercent: (json['commission_percent'] ?? '0.00').toString(),
        archived: json['archived'] == true,
        summary: InvestorSummary.fromJson(_map(json['summary'])),
        commercialRegistration: json['commercial_registration']?.toString(),
        notes: json['notes']?.toString(),
      );

  final String id;
  final String name;
  final bool isMain;
  final String currency;

  /// Of this partner's profit, the share that goes to the business ("15.00").
  final String commissionPercent;
  final bool archived;
  final InvestorSummary summary;
  final String? commercialRegistration;
  final String? notes;

  bool get paysCommission => (double.tryParse(commissionPercent) ?? 0) > 0;

  /// "15" rather than "15.00".
  String get commissionShort => commissionPercent.replaceFirst(RegExp(r'\.?0+$'), '');
}

/// The investors list and what this person may do with it.
@immutable
class InvestorsPage {
  const InvestorsPage({required this.investors, required this.canManage, required this.canReverse, required this.used, this.limit});

  factory InvestorsPage.fromJson(Map<String, dynamic> json) => InvestorsPage(
        investors: _list(json['investors']).map(Investor.fromJson).toList(),
        canManage: json['can_manage'] == true,
        canReverse: json['can_reverse'] == true,
        used: (json['used'] as num?)?.toInt() ?? 0,
        limit: (json['limit'] as num?)?.toInt(),
      );

  final List<Investor> investors;

  /// Adds investors and records money in and out (owner, manager, accountant).
  final bool canManage;

  /// Reverses a mistaken deposit or withdrawal (owner, manager).
  final bool canReverse;
  final int used;

  /// Investors the plan allows, the main one included; null is unlimited.
  final int? limit;

  bool get isFull => limit != null && used >= limit!;

  /// Who can fund a new contract: the ones not archived, the business's own capital first.
  List<Investor> get funders => investors.where((i) => !i.archived).toList();
}

/// One line of an investor's money, signed for their wallet.
@immutable
class InvestorEntry {
  const InvestorEntry({
    required this.id,
    required this.type,
    required this.amount,
    required this.occurredOn,
    required this.reversed,
    required this.reversible,
    this.note,
    this.contractId,
    this.contractReference,
    this.reversesEntryId,
  });

  factory InvestorEntry.fromJson(Map<String, dynamic> json) {
    final contract = _map(json['contract']);

    return InvestorEntry(
      id: json['id'].toString(),
      type: (json['type'] ?? '').toString(),
      amount: _money(json['amount']),
      occurredOn: (json['occurred_on'] ?? '').toString(),
      reversed: json['reversed'] == true,
      reversible: json['reversible'] == true,
      note: json['note']?.toString(),
      contractId: contract['id']?.toString(),
      contractReference: contract['reference']?.toString(),
      reversesEntryId: json['reverses_entry_id']?.toString(),
    );
  }

  final String id;

  /// deposit, withdrawal, funding_out, funding_back, principal_back, profit_share or commission.
  final String type;
  final Money amount;

  /// YYYY-MM-DD.
  final String occurredOn;
  final bool reversed;
  final bool reversible;
  final String? note;
  final String? contractId;
  final String? contractReference;
  final String? reversesEntryId;

  bool get isReversal => reversesEntryId != null;
}

/// A contract an investor funds, with what came back to them from it.
@immutable
class InvestorContract {
  const InvestorContract({
    required this.id,
    required this.reference,
    required this.status,
    required this.financed,
    required this.markup,
    required this.collected,
    this.customerName,
  });

  factory InvestorContract.fromJson(Map<String, dynamic> json) => InvestorContract(
        id: json['id'].toString(),
        reference: (json['reference'] ?? '').toString(),
        status: (json['status'] ?? 'active').toString(),
        financed: _money(json['financed']),
        markup: _money(json['markup_amount']),
        collected: _money(json['collected']),
        customerName: _map(json['customer'])['name']?.toString(),
      );

  final String id;
  final String reference;
  final String status;
  final Money financed;
  final Money markup;
  final Money collected;
  final String? customerName;

  Money get total => financed + markup;
}

/// One investor's page: the investor, profit month by month, their contracts and their entries.
@immutable
class InvestorDetail {
  const InvestorDetail({required this.investor, required this.profitByMonth, required this.contracts, required this.entries});

  factory InvestorDetail.fromJson(Map<String, dynamic> json) => InvestorDetail(
        investor: Investor.fromJson(json),
        profitByMonth: [for (final m in _list(json['profit_by_month'])) (month: (m['month'] ?? '').toString(), amount: _money(m['amount']))],
        contracts: _list(json['contracts']).map(InvestorContract.fromJson).toList(),
        entries: _list(json['entries']).map(InvestorEntry.fromJson).toList(),
      );

  final Investor investor;

  /// The last six months, oldest first ("2026-10").
  final List<({String month, Money amount})> profitByMonth;
  final List<InvestorContract> contracts;
  final List<InvestorEntry> entries;
}
