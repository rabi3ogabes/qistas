import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:in_app_purchase/in_app_purchase.dart';
import 'package:in_app_purchase_android/in_app_purchase_android.dart';

/// One way to buy a plan in the store (Win Plan PP13): a subscription's base plan, with the free trial the store
/// offers this person, if any. [price] is the store's own wording ("SAR 19.00"), in the person's currency.
@immutable
class StoreOffer {
  const StoreOffer({required this.productId, required this.key, required this.price, required this.period, this.trialDays, this.handle});

  final String productId;

  /// The base plan (and offer) this is, unique within the product.
  final String key;
  final String price;

  /// How often it renews, as the store says it: P1M (monthly) or P1Y (yearly).
  final String period;

  /// Days free before the first payment, when the store offers a trial to this person.
  final int? trialDays;

  /// What the store needs back to buy it.
  final Object? handle;

  bool get yearly => period == 'P1Y';
}

enum StorePurchaseStatus { pending, purchased, restored, failed, canceled }

/// What the store says happened to a purchase. A purchased or restored one is checked with the server before
/// anything unlocks; the store is told it was received only after that.
@immutable
class StorePurchaseUpdate {
  const StorePurchaseUpdate({required this.productId, required this.token, required this.status, this.handle});

  final String productId;
  final String token;
  final StorePurchaseStatus status;
  final Object? handle;
}

/// The phone's app store, behind one small surface so tests can stand in for it.
abstract class StoreGateway {
  Future<bool> isAvailable();

  /// The offers for these subscription ids, as the store presents them to this person.
  Future<List<StoreOffer>> offers(Set<String> productIds);

  /// Starts a purchase; the result arrives on [updates]. [accountRef] ties the purchase to the workspace.
  Future<void> buy(StoreOffer offer, {required String accountRef});

  /// Asks the store to send again what this person already bought (a new phone, a reinstall).
  Future<void> restore();

  Stream<List<StorePurchaseUpdate>> get updates;

  /// Tells the store the purchase was received.
  Future<void> complete(StorePurchaseUpdate update);
}

/// Google Play through the in_app_purchase plugin.
class PlayStoreGateway implements StoreGateway {
  PlayStoreGateway([InAppPurchase? store]) : _store = store ?? InAppPurchase.instance;

  final InAppPurchase _store;

  @override
  Future<bool> isAvailable() => _store.isAvailable();

  @override
  Future<List<StoreOffer>> offers(Set<String> productIds) async {
    final response = await _store.queryProductDetails(productIds);
    final byBasePlan = <String, StoreOffer>{};
    for (final details in response.productDetails.whereType<GooglePlayProductDetails>()) {
      final index = details.subscriptionIndex;
      final subscriptions = details.productDetails.subscriptionOfferDetails;
      if (index == null || subscriptions == null || index >= subscriptions.length) continue;
      final offer = subscriptions[index];
      final phases = offer.pricingPhases;
      if (phases.isEmpty) continue;
      final recurring = phases.last;
      final trial = phases.length > 1 && phases.first.priceAmountMicros == 0 ? _days(phases.first.billingPeriod) : null;
      final candidate = StoreOffer(
        productId: details.id,
        key: '${offer.basePlanId}:${offer.offerId ?? ''}',
        price: recurring.formattedPrice,
        period: recurring.billingPeriod,
        trialDays: trial,
        handle: details,
      );
      // One offer per base plan: the free trial when Google offers this person one, otherwise the plain plan.
      final group = '${details.id}:${offer.basePlanId}';
      final held = byBasePlan[group];
      if (held == null || (held.trialDays == null && candidate.trialDays != null)) byBasePlan[group] = candidate;
    }

    return byBasePlan.values.toList()..sort((a, b) => a.yearly == b.yearly ? 0 : (a.yearly ? 1 : -1));
  }

  @override
  Future<void> buy(StoreOffer offer, {required String accountRef}) async {
    final details = offer.handle;
    if (details is! ProductDetails) throw StateError('This offer did not come from the store.');
    await _store.buyNonConsumable(purchaseParam: PurchaseParam(productDetails: details, applicationUserName: accountRef));
  }

  @override
  Future<void> restore() => _store.restorePurchases();

  @override
  Stream<List<StorePurchaseUpdate>> get updates => _store.purchaseStream.map((list) => [
        for (final purchase in list)
          StorePurchaseUpdate(
            productId: purchase.productID,
            token: purchase.verificationData.serverVerificationData,
            status: switch (purchase.status) {
              PurchaseStatus.pending => StorePurchaseStatus.pending,
              PurchaseStatus.purchased => StorePurchaseStatus.purchased,
              PurchaseStatus.restored => StorePurchaseStatus.restored,
              PurchaseStatus.error => StorePurchaseStatus.failed,
              PurchaseStatus.canceled => StorePurchaseStatus.canceled,
            },
            handle: purchase,
          ),
      ]);

  @override
  Future<void> complete(StorePurchaseUpdate update) async {
    final purchase = update.handle;
    if (purchase is PurchaseDetails && purchase.pendingCompletePurchase) await _store.completePurchase(purchase);
  }

  /// "P14D", "P2W" or "P1M" as a number of days (a month counted as 30).
  static int? _days(String period) {
    final match = RegExp(r'^P(\d+)([DWMY])$').firstMatch(period);
    if (match == null) return null;
    final n = int.parse(match.group(1)!);

    return switch (match.group(2)) { 'D' => n, 'W' => n * 7, 'M' => n * 30, _ => n * 365 };
  }
}

/// The store on this phone: Google Play on Android, nothing elsewhere (the web build buys on the website). Tests give
/// their own.
final storeGatewayProvider = Provider<StoreGateway?>((ref) => !kIsWeb && Platform.isAndroid ? PlayStoreGateway() : null);
