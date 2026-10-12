<?php

namespace App\Billing;

use App\Billing\Store\PurchaseUnverifiable;
use App\Billing\Store\StoreBilling;
use App\Billing\Store\StorePurchase;
use App\Billing\Store\StoreUnavailable;
use App\Http\ApiException;
use App\Models\Plan;
use App\Models\StoreEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plans bought in a store (Win Plan PP13). A purchase token from the app is checked with the store, never trusted, and
 * puts the workspace on the plan its product buys until the period the store reports. The store's later messages are
 * applied once each, by message id, always by asking the store again what is true now.
 *
 * Only a hash of each token is kept: enough to recognise a purchase, not to use it. The phone buys with an account
 * reference made from the workspace, so a token bought for one workspace cannot be attached to another.
 */
final class StoreSubscriptions
{
    public const PROVIDER = 'google_play';

    public function __construct(
        private readonly StoreBilling $store,
        private readonly BillingSettings $settings,
        private readonly BillingNotices $notices,
    ) {}

    /** The workspace's account reference for store purchases: stable, not reversible, 64 characters as Google allows. */
    public static function accountRef(Tenant $tenant): string
    {
        return hash_hmac('sha256', 'store-account:'.$tenant->id, (string) config('app.key'));
    }

    /**
     * Attach a purchase the app reports to the workspace.
     *
     * @throws ApiException purchase_invalid, purchase_pending, purchase_inactive, purchase_elsewhere, store_unavailable
     */
    public function verify(Tenant $tenant, string $productId, string $purchaseToken, ?User $by = null): Subscription
    {
        $hash = hash('sha256', $purchaseToken);
        $holder = Subscription::query()->where('purchase_token_hash', $hash)->first();
        if ($holder !== null && $holder->tenant_id !== $tenant->id) {
            throw $this->elsewhere();
        }

        $plan = $this->settings->planForProduct($productId) ?? throw $this->invalid();
        try {
            $purchase = $this->store->verify($purchaseToken);
        } catch (PurchaseUnverifiable) {
            throw $this->invalid();
        } catch (StoreUnavailable) {
            throw new ApiException('store_unavailable', __('Google Play could not be reached. Try again in a moment.'), 503);
        }

        if ($purchase->productId !== $productId) {
            throw $this->invalid();
        }
        if ($purchase->accountRef !== null && ! hash_equals(self::accountRef($tenant), $purchase->accountRef)) {
            throw $this->elsewhere();
        }
        if ($purchase->state === 'pending') {
            throw new ApiException('purchase_pending', __('Google Play is still completing the payment. The plan turns on as soon as it is done.'), 422);
        }
        if (! $purchase->grantsAccess()) {
            throw new ApiException('purchase_inactive', __('This subscription is no longer active on Google Play.'), 422);
        }

        $subscription = DB::transaction(function () use ($tenant, $plan, $purchase, $hash, $productId, $by): Subscription {
            $before = $tenant->subscription()->lockForUpdate()->first();
            $subscription = $this->apply($tenant, $plan, $purchase, $hash);
            if ($this->changed($before, $subscription)) {
                Audit::record('billing.store_verified', $subscription, [
                    'plan' => $plan->key, 'product' => $productId, 'status' => $subscription->status,
                    'until' => $subscription->current_period_end?->toDateString(),
                ], $tenant->id, $by?->id);
            }

            return $subscription;
        });

        if (! $purchase->acknowledged) {
            try {
                $this->store->acknowledge($productId, $purchaseToken);
            } catch (Throwable $e) {
                // The next verify or notification tries again; Google allows three days.
                Log::warning('store acknowledge failed', ['error' => $e::class]);
            }
        }

        return $subscription;
    }

    /**
     * Apply one push from the store (Google Cloud Pub/Sub) once.
     *
     * @param  array<string, mixed>  $body
     * @return string what it did: renewed, grace, canceled, on_hold, paused, expired, refunded, unknown, ignored, test,
     *                duplicate or malformed
     *
     * @throws StoreUnavailable when the store could not be asked; the push is left to be sent again
     */
    public function notify(array $body): string
    {
        $message = is_array($body['message'] ?? null) ? $body['message'] : [];
        $id = (string) ($message['messageId'] ?? $message['message_id'] ?? '');
        $data = json_decode((string) base64_decode((string) ($message['data'] ?? ''), true), true);
        if ($id === '' || ! is_array($data)) {
            return 'malformed';
        }

        $event = StoreEvent::query()->where('provider', self::PROVIDER)->where('event_id', $id)->first();
        if ($event?->processed_at !== null) {
            return 'duplicate';
        }
        if ($event === null) {
            try {
                $event = (new StoreEvent)->forceFill(['provider' => self::PROVIDER, 'event_id' => $id, 'payload' => $data]);
                $event->save();
            } catch (UniqueConstraintViolationException) {
                return 'duplicate'; // the same push arrived twice at once
            }
        }

        $outcome = DB::transaction(fn (): string => $this->applyMessage($data));
        $event->forceFill(['outcome' => $outcome, 'processed_at' => now()])->save();

        return $outcome;
    }

    /** @param  array<string, mixed>  $data  Google's DeveloperNotification */
    private function applyMessage(array $data): string
    {
        if (($data['packageName'] ?? null) !== $this->settings->playPackage()) {
            return 'ignored';
        }
        if (isset($data['testNotification'])) {
            return 'test';
        }

        if (is_array($data['voidedPurchaseNotification'] ?? null)) {
            $subscription = $this->holding((string) ($data['voidedPurchaseNotification']['purchaseToken'] ?? ''));
            if ($subscription === null) {
                return 'unknown';
            }
            $subscription->forceFill(['status' => 'canceled', 'store_status' => 'VOIDED', 'auto_renew' => false])->save();
            $this->record($subscription, 'refunded');
            $this->notices->storeEvent($subscription, 'refunded');

            return 'refunded';
        }

        $notice = $data['subscriptionNotification'] ?? null;
        if (! is_array($notice)) {
            return 'ignored';
        }

        $token = (string) ($notice['purchaseToken'] ?? '');
        try {
            $purchase = $this->store->verify($token);
        } catch (PurchaseUnverifiable) {
            return 'unknown';
        }
        // A resubscription or an upgrade comes with a new token linked to the one the workspace holds.
        $subscription = $this->holding($token) ?? ($purchase->linkedToken !== null ? $this->holding($purchase->linkedToken) : null);
        if ($subscription === null) {
            return 'unknown';
        }
        if ($purchase->state === 'pending') {
            return 'pending';
        }

        // Google's "revoked" (12) is a refund: the plan ends now, whatever the purchase still says.
        $state = (int) ($notice['notificationType'] ?? 0) === 12 ? 'revoked' : $purchase->state;
        $plan = $this->settings->planForProduct($purchase->productId) ?? $subscription->plan;
        $applied = $this->apply($subscription->tenant, $plan, $state === $purchase->state ? $purchase : new StorePurchase(
            $purchase->productId, $state, $purchase->expiresAt, false, $purchase->acknowledged, $purchase->accountRef, $purchase->linkedToken, false, 'REVOKED',
        ), hash('sha256', $token));

        $outcome = match ($state) {
            'active' => 'renewed',
            'grace' => 'grace',
            'canceled' => 'canceled',
            'hold' => 'on_hold',
            'paused' => 'paused',
            'revoked' => 'refunded',
            default => 'expired',
        };
        $this->record($applied, $outcome);
        // The owner hears about a renewal, a payment the store could not take, and the end (Sprint 3 Task 1b).
        $this->notices->storeEvent($applied, $outcome);

        return $outcome;
    }

    private function apply(Tenant $tenant, Plan $plan, StorePurchase $purchase, string $hash): Subscription
    {
        return $tenant->subscribeTo($plan, self::status($purchase), $purchase->expiresAt, [
            'source' => 'play',
            'store_product_id' => $purchase->productId,
            'purchase_token_hash' => $hash,
            'auto_renew' => $purchase->autoRenew,
            'store_status' => $purchase->storeStatus !== '' ? $purchase->storeStatus : strtoupper($purchase->state),
        ]);
    }

    /** Our subscription status for what the store says. Only active, trialing and past_due keep the plan in force. */
    private static function status(StorePurchase $purchase): string
    {
        return match ($purchase->state) {
            'active' => $purchase->trial ? 'trialing' : 'active',
            'grace' => 'past_due',
            'canceled' => $purchase->expiresAt?->isFuture() ? 'active' : 'expired',
            'hold' => 'on_hold',
            'paused' => 'paused',
            'revoked' => 'canceled',
            default => 'expired',
        };
    }

    private function holding(string $token): ?Subscription
    {
        return $token === '' ? null : Subscription::query()->with(['tenant', 'plan'])->where('purchase_token_hash', hash('sha256', $token))->first();
    }

    private function changed(?Subscription $before, Subscription $after): bool
    {
        return $before === null
            || $before->purchase_token_hash !== $after->purchase_token_hash
            || $before->plan_id !== $after->plan_id
            || $before->status !== $after->status
            || $before->auto_renew !== $after->auto_renew
            || $before->current_period_end?->toIso8601String() !== $after->current_period_end?->toIso8601String();
    }

    private function record(Subscription $subscription, string $outcome): void
    {
        Audit::record('billing.store_event', $subscription, [
            'outcome' => $outcome, 'status' => $subscription->status, 'until' => $subscription->current_period_end?->toDateString(),
        ], $subscription->tenant_id);
    }

    private function invalid(): ApiException
    {
        return new ApiException('purchase_invalid', __('Google Play does not confirm this purchase.'), 422);
    }

    private function elsewhere(): ApiException
    {
        return new ApiException('purchase_elsewhere', __('This purchase belongs to another workspace.'), 409);
    }
}
