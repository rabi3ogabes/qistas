<?php

namespace App\Notifications\Alerts;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Settings\TenantSettings;
use App\Support\Format;
use App\Support\InLanguage;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;

/**
 * The morning summary (Win Plan PP9): once a day, at each person's chosen time on the workspace's clock, how many pay
 * today and how many are late, with what they owe. A day with nothing due and nobody late says nothing.
 */
final class DailyDigest
{
    public const LATE_LIMIT_SETTING = 'alerts.late_after_days';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly AlertQueries $queries,
        private readonly CurrentTenant $current,
    ) {}

    /** @return int how many people were told */
    public function run(Tenant $tenant, CarbonInterface $now): int
    {
        // Asked again at every run: the platform's switch can change between two of them.
        if (! Entitlements::for($tenant)->check(Feature::DailyDigest)->enabled()) {
            return 0;
        }

        $local = $tenant->localTime($now->copy());
        $lateLimit = (int) TenantSettings::for($tenant)->get(self::LATE_LIMIT_SETTING);
        $currency = (string) $tenant->currency;

        return $this->current->use($tenant, function () use ($tenant, $local, $lateLimit, $currency): int {
            $people = Recipients::of($tenant);
            $preferences = Preferences::forUsers($people->pluck('id')->all());
            $totals = null;
            $told = 0;

            foreach ($people as $person) {
                $choice = $preferences[$person->id];
                if (! $choice['daily_digest']['enabled'] || ! Recipients::isTime($choice['daily_digest']['time'], $local) || Preferences::isQuiet($choice, $local)) {
                    continue;
                }

                $key = "daily_digest:{$person->id}:{$local->toDateString()}";
                if ($this->notifier->decided([$key]) !== []) {
                    continue;
                }

                $totals ??= $this->queries->totals($local, $lateLimit);
                if ($totals['due_count'] === 0 && $totals['late_count'] === 0) {
                    $this->notifier->skip($person, 'daily_digest', $key);

                    continue;
                }

                $message = InLanguage::run((string) ($person->locale ?: 'en'), fn () => new PushMessage(
                    'daily_digest',
                    __('Today’s collections'),
                    trim(__('Due today: :due (:due_amount). Late: :late (:late_amount).', [
                        'due' => $totals['due_count'],
                        'due_amount' => Format::money($totals['due_amount'], $currency),
                        'late' => $totals['late_count'],
                        'late_amount' => Format::money($totals['late_amount'], $currency),
                    ]).($totals['past_limit'] > 0 ? ' '.__('Past your late limit: :count.', ['count' => $totals['past_limit']]) : '')),
                    ['route' => '/'],
                ));
                $this->notifier->notify($person, $message, [['type' => 'daily_digest', 'subject_type' => null, 'subject_id' => null, 'dedupe_key' => $key]]);
                $told++;
            }

            return $told;
        });
    }
}
