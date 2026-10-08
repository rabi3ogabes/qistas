<?php

namespace App\Http\Api;

use App\Entitlements\Entitlements;
use App\Models\Tenant;
use App\Models\User;

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
                'locale' => $user->locale,
                'two_factor' => $user->hasConfirmedTwoFactor(),
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'country' => $tenant->country,
                'currency' => $tenant->currency,
                'role' => $user->roleIn($tenant->id)?->value,
            ],
            'plan' => $entitlements['plan'],
            'entitlements' => $entitlements['features'],
        ];
    }
}
