import 'dart:async';

import 'package:qistas/core/billing/store_gateway.dart';

/// A Google Play that answers from what a test gives it. A purchase is reported on [updates] as soon as it is bought,
/// with the token `tok-<base plan>`, unless the test reports it itself.
class FakeStoreGateway implements StoreGateway {
  FakeStoreGateway({this.available = true, List<StoreOffer>? offers, this.reportsPurchases = true})
      : _offers = offers ??
            const [
              StoreOffer(productId: 'qistas_pro', key: 'monthly:trial', price: 'SAR 19.00', period: 'P1M', trialDays: 14),
              StoreOffer(productId: 'qistas_pro', key: 'yearly:', price: 'SAR 149.00', period: 'P1Y'),
            ];

  final bool available;
  final bool reportsPurchases;
  final List<StoreOffer> _offers;
  final _updates = StreamController<List<StorePurchaseUpdate>>.broadcast();

  /// What was bought, and with which account reference.
  final bought = <(StoreOffer, String)>[];

  /// The tokens the app told the store it received.
  final completed = <String>[];

  /// What a restore sends back.
  List<StorePurchaseUpdate> restorable = const [];
  Set<String>? askedFor;

  void report(List<StorePurchaseUpdate> updates) => _updates.add(updates);

  @override
  Future<bool> isAvailable() async => available;

  @override
  Future<List<StoreOffer>> offers(Set<String> productIds) async {
    askedFor = productIds;

    return [for (final offer in _offers) if (productIds.contains(offer.productId)) offer];
  }

  @override
  Future<void> buy(StoreOffer offer, {required String accountRef}) async {
    bought.add((offer, accountRef));
    if (reportsPurchases) {
      report([StorePurchaseUpdate(productId: offer.productId, token: 'tok-${offer.key.split(':').first}', status: StorePurchaseStatus.purchased)]);
    }
  }

  @override
  Future<void> restore() async {
    if (restorable.isNotEmpty) report(restorable);
  }

  @override
  Stream<List<StorePurchaseUpdate>> get updates => _updates.stream;

  @override
  Future<void> complete(StorePurchaseUpdate update) async => completed.add(update.token);
}
