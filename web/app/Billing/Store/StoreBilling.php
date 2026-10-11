<?php

namespace App\Billing\Store;

/** A store that sells the app's subscriptions (Google Play today; the App Store would be another). */
interface StoreBilling
{
    /**
     * Ask the store about a purchase token.
     *
     * @throws PurchaseUnverifiable when the store does not know the token (or it is not ours)
     * @throws StoreUnavailable when the store could not be asked; try again later
     */
    public function verify(string $purchaseToken): StorePurchase;

    /**
     * Tell the store the purchase was received; an unacknowledged Google Play purchase is refunded after three days.
     *
     * @throws StoreUnavailable
     */
    public function acknowledge(string $productId, string $purchaseToken): void;
}
