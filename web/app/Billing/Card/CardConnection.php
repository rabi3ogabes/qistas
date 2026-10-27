<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardAccount;
use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Connecting Qistas to the platform owner's Stripe account from /admin/billing (spec 2026-10-27 §8), so nothing has to
 * be set up in Stripe's dashboard: the account the keys open is checked, this site's webhook endpoint is made with
 * exactly the events Qistas follows and its signing secret kept, and the site's domain is registered for Apple Pay and
 * Google Pay. The gateway is resolved at each step because the keys it uses change here.
 */
final class CardConnection
{
    public function __construct(private readonly CardSettings $settings) {}

    /**
     * New keys, connected in the same step; when Stripe refuses them nothing is saved.
     *
     * @throws ValidationException (secret_key, publishable_key) when a key is not a Stripe key or the pair mixes modes
     * @throws CardRefused|CardUnavailable when Stripe refuses the keys or cannot be asked
     */
    public function replaceKeys(string $secret, string $publishable, User $by): CardAccount
    {
        // The endpoint made with the keys being replaced may belong to another account: delete it with those keys.
        $before = $this->settings->secretKey() !== null ? app(StripeGateway::class) : null;

        return DB::transaction(function () use ($secret, $publishable, $by, $before): CardAccount {
            $this->settings->saveKeys($secret, $publishable, $by);

            return $this->connect($by, $before);
        });
    }

    /**
     * Connect, or Reconnect: a new endpoint replaces the stored one, which is deleted only once the new one exists, so
     * a failure never leaves the site without one. A domain Stripe will not register (localhost) turns Apple Pay and
     * Google Pay off, not cards.
     *
     * @param  StripeGateway|null  $before  the gateway the stored endpoint was made with, when the keys just changed
     *
     * @throws CardRefused when the keys are missing or Stripe refuses them
     * @throws CardUnavailable when Stripe cannot be asked; nothing changed then
     */
    public function connect(User $by, ?StripeGateway $before = null): CardAccount
    {
        if ($this->settings->secretKey() === null) {
            throw new CardRefused(__('Save the two keys first.'));
        }

        $stripe = app(StripeGateway::class);
        $account = $stripe->account();
        $previous = $this->settings->webhookEndpointId();
        $endpoint = $stripe->createWebhook(self::webhookUrl(), CardSubscriptions::EVENTS);
        $this->settings->saveWebhook($endpoint['id'], $endpoint['secret']);
        if ($previous !== null && $previous !== $endpoint['id']) {
            $this->quietly('delete the previous webhook', fn () => ($before ?? $stripe)->deleteWebhook($previous));
        }

        $domain = parse_url((string) config('app.url'), PHP_URL_HOST);
        $wallets = is_string($domain) && $domain !== '' && $this->quietly('register the domain', fn () => $stripe->registerDomain($domain));
        $this->settings->saveAccount($account, $wallets);
        Audit::record('admin.card_connected', changes: ['mode' => $this->settings->mode(), 'account' => $account->id, 'wallets' => $wallets], userId: $by->id);

        return $account;
    }

    /** Disconnect: the endpoint is deleted when Stripe can be reached, and the keys are forgotten either way. */
    public function disconnect(User $by): void
    {
        $endpoint = $this->settings->webhookEndpointId();
        $deleted = $endpoint !== null && $this->settings->secretKey() !== null
            && $this->quietly('delete the webhook', fn () => app(StripeGateway::class)->deleteWebhook($endpoint));

        $this->settings->clear($by);
        Audit::record('admin.card_disconnected', changes: ['webhook_deleted' => $deleted], userId: $by->id);
    }

    /** Where Stripe sends events: always the site's own address, never the host an admin happens to browse from. */
    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('webhooks.stripe', absolute: false);
    }

    /** A step that may fail without stopping the connection; whether it worked. */
    private function quietly(string $step, Closure $call): bool
    {
        try {
            $call();

            return true;
        } catch (CardRefused|CardUnavailable $e) {
            Log::warning('stripe connection step failed', ['step' => $step, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
