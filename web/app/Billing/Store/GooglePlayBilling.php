<?php

namespace App\Billing\Store;

use App\Support\GoogleAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Play, through the Google Play Developer API (purchases.subscriptionsv2), as the service account the super
 * admin saved. The service account needs "View financial data" and "Manage orders and subscriptions" for the app in the
 * Play Console.
 */
final class GooglePlayBilling implements StoreBilling
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';

    /** @param  array<string, mixed>  $account */
    public function __construct(private readonly string $package, private readonly array $account) {}

    public function verify(string $purchaseToken): StorePurchase
    {
        try {
            $response = Http::withToken(GoogleAccessToken::for($this->account, self::SCOPE))->acceptJson()->timeout(15)
                ->get(self::API.'/'.rawurlencode($this->package).'/purchases/subscriptionsv2/tokens/'.rawurlencode($purchaseToken));
        } catch (Throwable $e) {
            throw new StoreUnavailable('Google Play could not be reached: '.$e::class);
        }

        // Google answers 400 for a malformed token and 404/410 for one it does not know (or no longer keeps).
        if (in_array($response->status(), [400, 404, 410], true)) {
            throw new PurchaseUnverifiable('Google Play does not know this purchase token.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new StoreUnavailable("Google Play answered {$response->status()}.");
        }

        return self::purchase($response->json());
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        try {
            $response = Http::withToken(GoogleAccessToken::for($this->account, self::SCOPE))->acceptJson()->timeout(15)
                ->post(self::API.'/'.rawurlencode($this->package).'/purchases/subscriptions/'.rawurlencode($productId).'/tokens/'.rawurlencode($purchaseToken).':acknowledge', (object) []);
        } catch (Throwable $e) {
            throw new StoreUnavailable('Google Play could not be reached: '.$e::class);
        }
        if (! $response->successful()) {
            throw new StoreUnavailable("Google Play answered {$response->status()} to the acknowledgement.");
        }
    }

    /**
     * Our reading of Google's SubscriptionPurchaseV2. Of several line items (an upgrade can leave two), the one that
     * runs longest counts.
     *
     * @param  array<string, mixed>  $data
     */
    public static function purchase(array $data): StorePurchase
    {
        $lines = array_values(array_filter(is_array($data['lineItems'] ?? null) ? $data['lineItems'] : [], 'is_array'));
        usort($lines, fn (array $a, array $b) => strcmp((string) ($b['expiryTime'] ?? ''), (string) ($a['expiryTime'] ?? '')));
        $line = $lines[0] ?? [];
        $status = (string) ($data['subscriptionState'] ?? '');

        return new StorePurchase(
            productId: (string) ($line['productId'] ?? ''),
            state: match ($status) {
                'SUBSCRIPTION_STATE_ACTIVE' => 'active',
                'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace',
                'SUBSCRIPTION_STATE_ON_HOLD' => 'hold',
                'SUBSCRIPTION_STATE_PAUSED' => 'paused',
                'SUBSCRIPTION_STATE_CANCELED' => 'canceled',
                'SUBSCRIPTION_STATE_PENDING' => 'pending',
                default => 'expired', // EXPIRED, PENDING_PURCHASE_CANCELED, or a state Google adds later
            },
            expiresAt: is_string($line['expiryTime'] ?? null) ? CarbonImmutable::parse($line['expiryTime']) : null,
            autoRenew: (bool) ($line['autoRenewingPlan']['autoRenewEnabled'] ?? false),
            acknowledged: ($data['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            accountRef: is_string($data['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null) ? $data['externalAccountIdentifiers']['obfuscatedExternalAccountId'] : null,
            linkedToken: is_string($data['linkedPurchaseToken'] ?? null) ? $data['linkedPurchaseToken'] : null,
            trial: isset($line['offerPhase']['freeTrial']),
            storeStatus: str_replace('SUBSCRIPTION_STATE_', '', $status),
        );
    }
}
