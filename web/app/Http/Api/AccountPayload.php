<?php

namespace App\Http\Api;

use App\Actions\ConfirmWorkspaceSetup;
use App\Entitlements\Entitlements;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Dates;

/**
 * Who is signed in, where, and what their plan allows: everything an app needs to draw itself, in one object.
 * The app never decides what is Free or Pro: it reads `entitlements`.
 */
final class AccountPayload
{
    /** @return array{user: array<string, mixed>, tenant: array<string, mixed>, plan: array{key: string, name: string}, entitlements: array<string, array<string, mixed>>} */
    public static function for(User $user, Tenant $tenant): array
    {
        $entitlements = Entitlements::for($tenant)->toArray();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'phone' => $user->phone,
                // The profile photo as a picture the app can show without another request; null without one.
                'photo' => $user->photo?->dataUri(),
                'member_since' => $user->created_at?->toDateString(),
                'locale' => $user->locale,
                'two_factor' => $user->hasConfirmedTwoFactor(),
                // The admin team never uses two-factor: the app shows no setting for it.
                'two_factor_available' => $user->mayUseTwoFactor(),
            ],
            'tenant' => self::tenant($user, $tenant),
            'plan' => $entitlements['plan'],
            'entitlements' => $entitlements['features'],
        ];
    }

    /**
     * The workspace part of the account, on its own: what the first-run card answers with after it is saved.
     *
     * @return array<string, mixed>
     */
    public static function tenant(User $user, Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'country' => $tenant->country,
            'currency' => $tenant->currency,
            'role' => $user->roleIn($tenant->id)?->value,
            // An admin's sandbox: the app marks it so nobody mistakes sample data for a customer's books.
            'is_test' => $tenant->is_test,
            // The business wants every phone to unlock Qistas (fingerprint, face or PIN) before showing its books.
            'require_app_lock' => $tenant->require_app_lock,
            // Hijri dates (Win Plan CT26): off, beside the Gregorian date, or first.
            'hijri_dates' => app(Dates::class)->mode($tenant),
            // The owner asked to delete the business: read-only, and erased after this day unless restored.
            'deletion_scheduled_for' => $tenant->restoreUntil(),

            // The first-run card (spec 2026-11-01 §3.3): is the business name and place confirmed, where it is, and whether
            // the owner may still change the place (not once a contract or a payment exists).
            'setup' => [
                'confirmed' => $tenant->isSetUp(),
                'country' => (string) $tenant->country,
                'currency' => (string) $tenant->currency,
                'timezone' => $tenant->localTimezone(),
                // Found from the visitor's network, device time zone or browser language (not the product's default, not the owner's own pick), and a
                // country the product has a currency for: the card then asks for a tap, not a choice.
                'country_detected' => in_array($tenant->country_source, ['header', 'timezone', 'language'], true)
                    && array_key_exists(strtoupper((string) $tenant->country), (array) config('qistas.countries')),
                'can_change_region' => app(ConfirmWorkspaceSetup::class)->regionIsOpen($tenant),
            ],
        ];
    }
}
