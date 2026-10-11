import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/billing/store_gateway.dart';
import '../../data/billing.dart';

/// Where the workspace's plan stands and what the app may sell (Win Plan PP13).
final billingProvider = FutureProvider.autoDispose<BillingState>((ref) => ref.watch(apiProvider).billing());

/// The store's offers for the plans on sale, priced as the store prices them for this person. Empty when the store is
/// not switched on for this workspace, this person is not the owner, or the phone has no store.
final storeOffersProvider = FutureProvider.autoDispose<List<StoreOffer>>((ref) async {
  final store = ref.watch(storeGatewayProvider);
  final billing = await ref.watch(billingProvider.future);
  if (store == null || !billing.storeAvailable || billing.products.isEmpty || !await store.isAvailable()) return const [];

  return store.offers({for (final product in billing.products) product.productId});
});

enum PurchasePhase { idle, working, pending, done, failed, nothingToRestore }

@immutable
class PurchaseState {
  const PurchaseState(this.phase, [this.error]);

  const PurchaseState.idle() : this(PurchasePhase.idle);

  final PurchasePhase phase;
  final Object? error;
}

final purchaseControllerProvider = NotifierProvider<PurchaseController, PurchaseState>(PurchaseController.new);

/// Buys in the store and hands every purchase the store reports to the server, which asks Google before Pro turns on.
/// The store is told the purchase was received only once the server has it. Watched at the root of the app, so a
/// payment that completes later (a pending one, or one made before a crash) is still taken in on the next start.
class PurchaseController extends Notifier<PurchaseState> {
  @override
  PurchaseState build() {
    final store = ref.watch(storeGatewayProvider);
    if (store != null) {
      final subscription = store.updates.listen((updates) => unawaited(_take(updates)));
      ref.onDispose(subscription.cancel);
    }

    return const PurchaseState.idle();
  }

  Future<void> buy(StoreOffer offer, String accountRef) async {
    final store = ref.read(storeGatewayProvider);
    if (store == null) return;
    state = const PurchaseState(PurchasePhase.working);
    try {
      await store.buy(offer, accountRef: accountRef);
    } catch (error) {
      state = PurchaseState(PurchasePhase.failed, error);
    }
  }

  /// Asks the store for what this Google account already bought; each comes back as a purchase to take in.
  Future<void> restore() async {
    final store = ref.read(storeGatewayProvider);
    if (store == null) return;
    state = const PurchaseState(PurchasePhase.working);
    try {
      await store.restore();
      if (state.phase == PurchasePhase.working) state = const PurchaseState(PurchasePhase.nothingToRestore);
    } catch (error) {
      state = PurchaseState(PurchasePhase.failed, error);
    }
  }

  void dismiss() => state = const PurchaseState.idle();

  Future<void> _take(List<StorePurchaseUpdate> updates) async {
    for (final update in updates) {
      switch (update.status) {
        case StorePurchaseStatus.pending:
          state = const PurchaseState(PurchasePhase.pending);
        case StorePurchaseStatus.canceled:
          state = const PurchaseState.idle();
        case StorePurchaseStatus.failed:
          state = const PurchaseState(PurchasePhase.failed);
        case StorePurchaseStatus.purchased || StorePurchaseStatus.restored:
          await _verify(update);
      }
    }
  }

  Future<void> _verify(StorePurchaseUpdate update) async {
    // Nobody signed in: the store sends it again the next time the app starts.
    if (!(ref.read(authProvider).valueOrNull?.isSignedIn ?? false)) return;
    state = const PurchaseState(PurchasePhase.working);
    try {
      await ref.read(apiProvider).verifyPlayPurchase(productId: update.productId, purchaseToken: update.token);
      await ref.read(storeGatewayProvider)?.complete(update);
      ref.invalidate(billingProvider);
      await ref.read(authProvider.notifier).refresh();
      state = const PurchaseState(PurchasePhase.done);
    } on ApiException catch (error) {
      state = error.code == 'purchase_pending' ? const PurchaseState(PurchasePhase.pending) : PurchaseState(PurchasePhase.failed, error);
    } catch (error) {
      state = PurchaseState(PurchasePhase.failed, error);
    }
  }
}
