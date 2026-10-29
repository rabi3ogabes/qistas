<?php

namespace App\Notifications\Alerts;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The home-screen widget's morning refresh (daily-habit wave A, H3): once a day, at each person's morning-summary time on
 * the workspace's clock, one silent push to each of their phones, so the widget is fresh before the owner opens the app.
 * Silent means data only: nothing is shown, no inbox entry is made, and it does not go through the Notifier, whose dedupe
 * log, channels and quiet hours are for things a person is told.
 */
final class GlanceRefresh
{
    /** What the app and the Android widget listen for. */
    public const TYPE = 'glance.refresh';

    /** Longer than a day, so a guard claimed at the start of its local day still stands when that day ends. */
    private const GUARD_HOURS = 36;

    public function __construct(
        private readonly PushSender $sender,
        private readonly CurrentTenant $current,
    ) {}

    /** @return int how many people had a phone told */
    public function run(Tenant $tenant, CarbonInterface $now): int
    {
        // Asked again at every run: the platform's switch and the plan can change between two of them.
        if (! Entitlements::for($tenant)->check(Feature::HomeWidget)->enabled()) {
            return 0;
        }

        $local = $tenant->localTime($now->copy());
        $guardUntil = $now->copy()->addHours(self::GUARD_HOURS);

        return $this->current->use($tenant, function () use ($tenant, $local, $guardUntil): int {
            $people = Recipients::of($tenant);
            $ids = $people->pluck('id')->all();
            $preferences = Preferences::forUsers($ids);
            $phones = PushToken::query()->whereIn('user_id', $ids)->whereNull('revoked_at')->get()->groupBy('user_id');
            $message = PushMessage::silent(self::TYPE);
            $told = 0;

            foreach ($people as $person) {
                if (! Recipients::isTime($preferences[$person->id]['daily_digest']['time'], $local)) {
                    continue;
                }

                // Claimed only once there is a phone to tell: one that signs in later this morning is still refreshed.
                $own = $phones->get($person->id);
                if ($own === null || ! Cache::add("glance:{$tenant->id}:{$person->id}:{$local->toDateString()}", 1, $guardUntil)) {
                    continue;
                }

                $delivered = false;
                foreach ($own as $phone) {
                    $delivered = $this->sender->send($phone, $message) || $delivered;
                }
                if ($delivered) {
                    $told++;
                }
            }

            return $told;
        });
    }
}
