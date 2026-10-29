<?php

namespace App\Notifications;

use App\Tenancy\TenantRole;

/**
 * Every kind of notice Qistas sends (Sprint 3 Task 1b), the message types each covers, and where it goes unless the person
 * or the admin says otherwise. A kind is what a person switches on or off; its channels are where it reaches them: in the
 * app (the inbox, and a push to each phone), by e-mail, or by text message.
 */
final class Catalog
{
    public const CHANNELS = ['push', 'email', 'sms'];

    private const APP_ONLY = ['push' => true, 'email' => false, 'sms' => false];

    private const APP_AND_EMAIL = ['push' => true, 'email' => true, 'sms' => false];

    /** @var array<string, array{group: string, types: list<string>, channels: array{push: bool, email: bool, sms: bool}}> */
    public const KINDS = [
        'daily_digest' => ['group' => 'alerts', 'types' => ['daily_digest'], 'channels' => self::APP_ONLY],
        'instalment_due' => ['group' => 'alerts', 'types' => ['instalment_due'], 'channels' => self::APP_ONLY],
        'instalment_late' => ['group' => 'alerts', 'types' => ['instalment_late'], 'channels' => self::APP_ONLY],
        'day_close_reminder' => ['group' => 'alerts', 'types' => ['day_close_reminder'], 'channels' => self::APP_ONLY],
        'day_close_difference' => ['group' => 'alerts', 'types' => ['day_close_difference'], 'channels' => self::APP_ONLY],
        'billing_renewal_due' => ['group' => 'billing', 'types' => ['billing.renewal_due', 'billing.trial_ending'], 'channels' => self::APP_AND_EMAIL],
        'billing_renewed' => ['group' => 'billing', 'types' => ['billing.renewed', 'billing.approved'], 'channels' => self::APP_AND_EMAIL],
        'billing_payment_failed' => ['group' => 'billing', 'types' => ['billing.payment_failed', 'billing.rejected', 'billing.action_needed'], 'channels' => self::APP_AND_EMAIL],
        'billing_expired' => ['group' => 'billing', 'types' => ['billing.expired'], 'channels' => self::APP_AND_EMAIL],
        'support_reply' => ['group' => 'support', 'types' => ['support.reply'], 'channels' => self::APP_AND_EMAIL],
    ];

    /** The kinds of the end-of-day close (Feature::DayClose), offered only while the workspace has it. */
    public const DAY_CLOSE_KINDS = ['day_close_reminder', 'day_close_difference'];

    /**
     * Every kind a workspace can be offered: all of them, less the two of the end-of-day close when it does not have that
     * feature ([$dayClose]: switched on for the platform and in its plan; the caller asks the entitlements, so this stays a
     * plain list). A person's choices for a kind left out are kept as they are, for when it comes back.
     *
     * @return array<string, array{group: string, types: list<string>, channels: array{push: bool, email: bool, sms: bool}}>
     */
    public static function offered(bool $dayClose): array
    {
        return $dayClose ? self::KINDS : array_diff_key(self::KINDS, array_flip(self::DAY_CLOSE_KINDS));
    }

    /**
     * The kinds a person with [$role] in the workspace can choose about, among those it is offered ({@see offered()}): the
     * billing ones are the owners', so is the notice of a difference found at the close of a day, and the evening reminder
     * is for whoever may close the day.
     *
     * @return array<string, array{group: string, types: list<string>, channels: array{push: bool, email: bool, sms: bool}}>
     */
    public static function forRole(?TenantRole $role, bool $dayClose): array
    {
        return array_filter(self::offered($dayClose), fn (array $definition, string $kind): bool => match (true) {
            $definition['group'] === 'billing', $kind === 'day_close_difference' => $role === TenantRole::Owner,
            $kind === 'day_close_reminder' => $role?->canWrite() ?? false,
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** The kind a message type belongs to; null for a type no kind names (it goes to the app, as always). */
    public static function kindOf(string $type): ?string
    {
        foreach (self::KINDS as $kind => $definition) {
            if (in_array($type, $definition['types'], true)) {
                return $kind;
            }
        }

        return null;
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'daily_digest' => __('Morning summary'),
            'instalment_due' => __('On the due date'),
            'instalment_late' => __('When an instalment is late'),
            'day_close_reminder' => __('Evening reminder to close the day'),
            'day_close_difference' => __('When a day is closed with a cash difference'),
            'billing_renewal_due' => __('Before your plan ends'),
            'billing_renewed' => __('When your plan renews'),
            'billing_payment_failed' => __('When a payment fails'),
            'billing_expired' => __('When your plan ends'),
            'support_reply' => __('Support replies'),
            default => $kind,
        };
    }

    public static function description(string $kind): string
    {
        return match ($kind) {
            'daily_digest' => __('Who pays today, who is late, and how much.'),
            'instalment_due' => __('An alert for each instalment that falls due today.'),
            'instalment_late' => __('Once the grace days are over, until it is paid.'),
            'day_close_reminder' => __('At the closing time, while the day is still open and has payments or something to look at.'),
            'day_close_difference' => __('Owners only: when a team member closes the day and the cash counted does not match.'),
            'billing_renewal_due' => __('Seven days and one day before a plan that does not renew by itself ends.'),
            'billing_renewed' => __('A renewal, or a bank transfer the Qistas team confirmed.'),
            'billing_payment_failed' => __('The store could not take a payment, or a transfer was not confirmed.'),
            'billing_expired' => __('The workspace is on Free again; nothing is deleted.'),
            'support_reply' => __('When the Qistas team answers you.'),
            default => '',
        };
    }

    public static function group(string $group): string
    {
        return match ($group) {
            'alerts' => __('Instalments'),
            'billing' => __('Your plan and payments'),
            'support' => __('Support'),
            default => $group,
        };
    }

    public static function channel(string $channel): string
    {
        return match ($channel) {
            'push' => __('In the app'),
            'email' => __('By e-mail'),
            'sms' => __('By text message'),
            default => $channel,
        };
    }
}
