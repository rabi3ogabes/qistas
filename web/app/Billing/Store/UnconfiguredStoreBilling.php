<?php

namespace App\Billing\Store;

/** Google Play before the super admin saves its package name and service account: it decides nothing. */
final class UnconfiguredStoreBilling implements StoreBilling
{
    public function verify(string $purchaseToken): StorePurchase
    {
        throw new StoreUnavailable('Google Play is not set up yet.');
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        throw new StoreUnavailable('Google Play is not set up yet.');
    }
}
