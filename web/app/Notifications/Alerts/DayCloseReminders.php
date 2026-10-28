<?php

namespace App\Notifications\Alerts;

use App\Domain\DayClose\DayCloseGuard;
use App\Domain\DayClose\DayTotals;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Settings\TenantSettings;
use App\Support\InLanguage;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;

/**
 * The evening reminder to close the day (daily-habit wave A, H4): from the workspace's closing time, for two hours, on its
 * own clock, each person who may close the day is told once, while the day is still open and has payments in it or
 * something worth a look. A day with neither says nothing, and the skip is recorded as the morning summary records it.
 * The window never reaches past midnight: after it the day asked about is the next one.
 */
final class DayCloseReminders
{
    /** What the app listens for, and the kind a person switches on or off. */
    public const TYPE = 'day_close_reminder';

    /** How long after the closing time a reminder may still go out. */
    private const WINDOW_HOURS = 2;

    public function __construct(
        private readonly Notifier $notifier,
        private readonly CurrentTenant $current,
    ) {}

    /** @return int how many people were told */
    public function run(Tenant $tenant, CarbonInterface $now): int
    {
        // Asked again at every run, before anything else: the platform's switch and the plan can change between two of them.
        if (! Entitlements::for($tenant)->check(Feature::DayClose)->enabled()) {
            return 0;
        }

        $day = WorkspaceDay::of($tenant, $now);
        $from = $day->atTime((string) TenantSettings::for($tenant)->get(DayClose::CLOSING_TIME_SETTING));
        if ($now->lessThan($from) || $now->greaterThanOrEqualTo($from->addHours(self::WINDOW_HOURS))) {
            return 0;
        }

        // A day that stands closed needs no reminder (one that was reopened does), and its figures are not worked out.
        if (DayCloseGuard::isClosed($tenant, $day)) {
            return 0;
        }

        $figures = DayTotals::for($tenant, $day);
        $somethingToSay = $figures['count'] > 0 || $figures['non_cash'] !== [] || $figures['watch'] !== [];
        $local = $tenant->localTime($now->copy());

        return $this->current->use($tenant, function () use ($tenant, $day, $local, $somethingToSay): int {
            $people = Recipients::of($tenant);
            $preferences = Preferences::forUsers($people->pluck('id')->all());

            // One key for each person for each workspace's day: a person in two workspaces is told in both, and the log's
            // key is unique across all of them.
            $keys = [];
            foreach ($people as $person) {
                $keys[$person->id] = self::TYPE.":{$tenant->id}:{$person->id}:{$day->date}";
            }
            $decided = array_flip($this->notifier->decided(array_values($keys)));
            $told = 0;

            foreach ($people as $person) {
                $choice = $preferences[$person->id];
                $key = $keys[$person->id];
                if (! $choice[self::TYPE]['enabled'] || Preferences::isQuiet($choice, $local) || isset($decided[$key])) {
                    continue;
                }

                if (! $somethingToSay) {
                    $this->notifier->skip($person, self::TYPE, $key);

                    continue;
                }

                $message = InLanguage::run((string) ($person->locale ?: 'en'), fn () => new PushMessage(
                    self::TYPE,
                    __('Time to close the day'),
                    __('Count the cash in the till and close today.'),
                    ['route' => '/day-close'],
                ));
                $this->notifier->notify($person, $message, [['type' => self::TYPE, 'subject_type' => null, 'subject_id' => null, 'dedupe_key' => $key]]);
                $told++;
            }

            return $told;
        });
    }
}
