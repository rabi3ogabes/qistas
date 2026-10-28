<?php

namespace App\Notifications\Alerts;

use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Support\Format;
use App\Support\InLanguage;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the owners when someone else closes a day with a difference between the cash counted and the cash recorded
 * (daily-habit wave A, H4): each owner once for each close, in their own language, unless they switched the kind off;
 * in their quiet hours the notice waits in their inbox without a push. The message names the day, who closed it and the
 * difference with its sign, and never a customer. A close that nobody is told about is still a close: this never throws.
 */
final class DayCloseDifference
{
    /** What the app listens for, and the kind a person switches on or off. */
    public const TYPE = 'day_close_difference';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly CurrentTenant $current,
    ) {}

    /**
     * @param  User  $closer  who closed the day, named in the message (CloseDay tells no one when that is an owner)
     * @return int how many owners were told
     */
    public function tell(Tenant $tenant, DayClose $close, User $closer): int
    {
        try {
            return $this->current->use($tenant, fn (): int => $this->tellOwners($tenant, $close, $closer));
        } catch (Throwable $e) {
            // The close is already written and signed; only the telling failed. Nothing a person could be recognised by.
            Log::warning('day close difference notice failed', ['close' => $close->id, 'error' => $e::class]);

            return 0;
        }
    }

    private function tellOwners(Tenant $tenant, DayClose $close, User $closer): int
    {
        $owners = $tenant->users()->wherePivot('role', TenantRole::Owner->value)->get();
        $preferences = Preferences::forUsers($owners->pluck('id')->all());
        $local = $tenant->localTime(now());

        $keys = [];
        foreach ($owners as $owner) {
            // Not the close's alone: the log's key is unique, and there may be more than one owner.
            $keys[$owner->id] = self::TYPE.":{$close->id}:{$owner->id}";
        }
        $decided = array_flip($this->notifier->decided(array_values($keys)));
        $told = 0;

        foreach ($owners as $owner) {
            $key = $keys[$owner->id];
            if (isset($decided[$key])) {
                continue;
            }

            try {
                $message = InLanguage::run((string) ($owner->locale ?: 'en'), fn () => $this->message($tenant, $close, $closer));
                // The notifier decides the channels, and with the owner's switch off it sends nothing and keeps the skip.
                // Quiet hours only keep a phone from ringing: a close happens once and nothing would tell the owner later,
                // so the notice still waits in their inbox, and the key is recorded all the same.
                $this->notifier->notify(
                    $owner,
                    $message,
                    [['type' => self::TYPE, 'subject_type' => 'day_close', 'subject_id' => $close->id, 'dedupe_key' => $key]],
                    interrupt: ! Preferences::isQuiet($preferences[$owner->id], $local),
                );
                $told++;
            } catch (Throwable $e) {
                // One owner's failure never keeps the next one from being told.
                Log::warning('day close difference notice failed', ['close' => $close->id, 'error' => $e::class]);
            }
        }

        return $told;
    }

    private function message(Tenant $tenant, DayClose $close, User $closer): PushMessage
    {
        $difference = Money::add($close->difference, '0', 2);
        // A shortage carries the minus its language writes; an excess carries a plus, so the sign is always there.
        $amount = Format::money($difference, (string) $tenant->currency);
        if (Money::isPositive($difference)) {
            $amount = '+'.$amount;
        }

        return new PushMessage(
            self::TYPE,
            __('Cash difference at the close'),
            __(':name closed :day with a cash difference of :amount.', [
                'name' => $closer->name,
                'day' => Carbon::parse($close->day, 'UTC')->translatedFormat('j F Y'),
                'amount' => $amount,
            ]),
            ['route' => '/day-close'],
        );
    }
}
