<?php

namespace App\Billing\Card;

use App\Models\Tenant;
use App\Models\User;

/**
 * A workspace's customer in Stripe (spec 2026-10-27 §5): made at its first checkout, with the business name, the
 * owner's e-mail and language, and the workspace id in its metadata. A customer belongs to the mode its keys were in;
 * when the platform moves from test keys to live keys, the workspace gets a new one.
 */
final class CardCustomers
{
    public function __construct(private readonly StripeGateway $stripe, private readonly CardSettings $settings) {}

    public function for(Tenant $tenant, User $owner): string
    {
        $live = $this->settings->mode() === 'live';
        if ($tenant->stripe_customer_id !== null && $tenant->stripe_livemode === $live) {
            return $tenant->stripe_customer_id;
        }

        // One key per workspace and mode: two checkouts opened at the same moment get the same customer.
        $id = $this->stripe->createCustomer([
            'name' => $tenant->name,
            'email' => $owner->email,
            'locale' => (string) ($owner->locale ?: 'en'),
            'tenant_id' => $tenant->id,
        ], hash('sha256', 'customer|'.$tenant->id.'|'.($live ? 'live' : 'test')));

        $tenant->forceFill(['stripe_customer_id' => $id, 'stripe_livemode' => $live])->save();

        return $id;
    }

    /** The workspace a Stripe customer belongs to: the only way from Stripe's messages back to a workspace. */
    public function tenantFor(string $customerId, bool $livemode): ?Tenant
    {
        return Tenant::query()->where('stripe_customer_id', $customerId)->where('stripe_livemode', $livemode)->first();
    }
}
