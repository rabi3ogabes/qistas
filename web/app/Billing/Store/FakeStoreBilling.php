<?php

namespace App\Billing\Store;

/**
 * A store that answers from a list it is given: for tests and for trying the purchase flow locally without Google.
 * Never bound in production (see AppServiceProvider).
 */
final class FakeStoreBilling implements StoreBilling
{
    /** @var array<string, StorePurchase> */
    private array $purchases = [];

    /** @var list<string> the tokens acknowledged, in order */
    public array $acknowledged = [];

    /** Answer as a store that cannot be reached. */
    public bool $unreachable = false;

    public function record(string $purchaseToken, StorePurchase $purchase): void
    {
        $this->purchases[$purchaseToken] = $purchase;
    }

    public function verify(string $purchaseToken): StorePurchase
    {
        if ($this->unreachable) {
            throw new StoreUnavailable('The fake store is set to be unreachable.');
        }

        return $this->purchases[$purchaseToken] ?? throw new PurchaseUnverifiable('Unknown purchase token.');
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        if ($this->unreachable) {
            throw new StoreUnavailable('The fake store is set to be unreachable.');
        }
        if (isset($this->purchases[$purchaseToken])) {
            $this->purchases[$purchaseToken] = $this->purchases[$purchaseToken]->acknowledgedCopy();
        }
        $this->acknowledged[] = $purchaseToken;
    }
}
