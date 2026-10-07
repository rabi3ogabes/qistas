<?php

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/** Turns the security-relevant authentication events into audit entries. Failures are audited where they happen. */
final class AuditAuthEvents
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'loggedIn',
            PasswordReset::class => 'passwordReset',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
        ];
    }

    public function loggedIn(Login $event): void
    {
        Audit::record('login.succeeded', userId: $event->user->getAuthIdentifier());
    }

    public function passwordReset(PasswordReset $event): void
    {
        Audit::record('password.reset', userId: $event->user->getAuthIdentifier());
    }

    public function twoFactorEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record('two_factor.enabled', userId: $event->user->getAuthIdentifier());
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record('two_factor.disabled', userId: $event->user->getAuthIdentifier());
    }
}
