import 'package:flutter/foundation.dart';

/// A paid plan the store sells, and the store's id for it (Win Plan PP13).
@immutable
class StoreProductRef {
  const StoreProductRef({required this.planKey, required this.productId});

  factory StoreProductRef.fromJson(Map<String, dynamic> json) =>
      StoreProductRef(planKey: (json['plan_key'] ?? '').toString(), productId: (json['product_id'] ?? '').toString());

  final String planKey;
  final String productId;
}

/// Where the workspace's plan stands and what the app may sell (GET /billing). The app offers a purchase only when
/// [storeAvailable]: the owner of a real workspace, with Google Play switched on and set up on the platform.
@immutable
class BillingState {
  const BillingState({
    required this.planKey,
    required this.planName,
    required this.canBuy,
    required this.storeAvailable,
    required this.products,
    this.status,
    this.until,
    this.source,
    this.autoRenew,
    this.period,
    this.accountRef,
  });

  factory BillingState.fromJson(Map<String, dynamic> json) {
    final plan = (json['plan'] as Map?)?.cast<String, dynamic>() ?? const {};
    final store = (json['store'] as Map?)?.cast<String, dynamic>() ?? const {};

    return BillingState(
      planKey: (plan['key'] ?? 'free').toString(),
      planName: (plan['name'] ?? '').toString(),
      status: json['status'] as String?,
      until: json['until'] as String?,
      source: json['source'] as String?,
      autoRenew: json['auto_renew'] as bool?,
      period: json['period'] as String?,
      canBuy: json['can_buy'] == true,
      storeAvailable: store['available'] == true,
      accountRef: store['account_ref'] as String?,
      products: [for (final item in (store['products'] as List?) ?? const []) StoreProductRef.fromJson((item as Map).cast<String, dynamic>())],
    );
  }

  final String planKey;
  final String planName;
  final String? status;

  /// The last day of the period paid (yyyy-MM-dd), or null.
  final String? until;

  /// play, appstore, web, manual, stripe or admin; only while a paid plan is current.
  final String? source;
  final bool? autoRenew;

  /// month or year, for a plan paid by period.
  final String? period;
  final bool canBuy;
  final bool storeAvailable;
  final String? accountRef;
  final List<StoreProductRef> products;

  bool get paidThroughStore => source == 'play';

  /// A current plan paid or given outside the store (by card or transfer on the website, or by the team). The app shows
  /// it as it stands and never names, prices or offers that way to pay (Google Play's payments policy).
  bool get paidOutsideStore => const {'stripe', 'manual', 'admin'}.contains(source);

  /// The store's id for [planKey], when it is on sale in the store.
  String? productFor(String planKey) => products.where((p) => p.planKey == planKey).firstOrNull?.productId;
}
