<?php

namespace App\Notifications\Alerts;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Support\Format;
use App\Support\InLanguage;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An alert for each instalment (Win Plan PP9): on its due date, and once its grace days are over, again every day, week
 * or month as each person chose, until it is paid. One run's alerts for a person arrive as one push that names them;
 * each instalment is still logged on its own, so none is ever repeated before its time.
 */
final class InstalmentAlerts
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly AlertQueries $queries,
        private readonly CurrentTenant $current,
    ) {}

    /** @return int how many pushes went out */
    public function run(Tenant $tenant, CarbonInterface $now): int
    {
        if (! Entitlements::for($tenant)->check(Feature::InstalmentAlerts)->enabled()) {
            return 0;
        }

        $local = $tenant->localTime($now->copy());
        $currency = (string) $tenant->currency;

        return $this->current->use($tenant, function () use ($tenant, $local, $currency): int {
            $people = Recipients::of($tenant);
            $preferences = Preferences::forUsers($people->pluck('id')->all());
            $dueToday = null;
            $late = null;
            $pushes = 0;

            foreach ($people as $person) {
                $choice = $preferences[$person->id];
                // Alerts come with the person's morning, whether or not they take the summary.
                if (! Recipients::isTime($choice['daily_digest']['time'], $local) || Preferences::isQuiet($choice, $local)) {
                    continue;
                }

                if ($choice['instalment_due']['enabled']) {
                    $dueToday ??= $this->queries->dueOn($local);
                    $keyed = [];
                    foreach ($dueToday as $row) {
                        $keyed["instalment_due:{$person->id}:{$row->id}:{$local->toDateString()}"] = $row;
                    }
                    $pushes += $this->alert($person, 'instalment_due', $keyed, $currency);
                }

                if ($choice['instalment_late']['enabled']) {
                    $late ??= $this->queries->late($local);
                    $every = Preferences::REPEAT_DAYS[$choice['instalment_late']['repeat']] ?? 7;
                    $keyed = [];
                    foreach ($late as $row) {
                        // The first late day starts round 0; each round lasts $every days.
                        $round = intdiv(max(1, $row->days_late) - 1, $every);
                        $keyed["instalment_late:{$person->id}:{$row->id}:{$round}"] = $row;
                    }
                    $pushes += $this->alert($person, 'instalment_late', $keyed, $currency);
                }
            }

            return $pushes;
        });
    }

    /** @param  array<string, object>  $keyed  instalment rows by dedupe key */
    private function alert(User $person, string $type, array $keyed, string $currency): int
    {
        $pending = array_diff_key($keyed, array_flip($this->notifier->decided(array_keys($keyed))));
        if ($pending === []) {
            return 0;
        }

        $rows = array_values($pending);
        $message = InLanguage::run((string) ($person->locale ?: 'en'), fn () => $this->message($type, $rows, $currency));
        $covers = [];
        foreach ($pending as $key => $row) {
            $covers[] = ['type' => $type, 'subject_type' => 'installment', 'subject_id' => $row->id, 'dedupe_key' => $key];
        }
        $this->notifier->notify($person, $message, $covers);

        return 1;
    }

    /** @param  list<object>  $rows */
    private function message(string $type, array $rows, string $currency): PushMessage
    {
        $late = $type === 'instalment_late';

        if (count($rows) === 1) {
            $row = $rows[0];
            $values = [
                'name' => $row->customer_name,
                'amount' => Format::money(AlertQueries::remaining($row), $currency),
                'reference' => 'C-'.str_pad((string) $row->contract_number, 4, '0', STR_PAD_LEFT),
                'date' => Carbon::parse(substr((string) $row->due_date, 0, 10))->translatedFormat('j F Y'),
            ];

            return new PushMessage(
                $type,
                $late ? __('Late: :name', $values) : __('Due today: :name', $values),
                $late ? __(':amount for contract :reference has been late since :date.', $values) : __(':amount for contract :reference.', $values),
                ['route' => '/contracts/'.$row->contract_id],
            );
        }

        $names = array_values(array_unique(array_map(fn (object $row) => (string) $row->customer_name, $rows)));
        $shown = implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ', +'.(count($names) - 3) : '');

        return new PushMessage(
            $type,
            $late ? __('Late instalments: :count', ['count' => count($rows)]) : __('Instalments due today: :count', ['count' => count($rows)]),
            $shown,
            ['route' => $late ? '/remind?scope=late' : '/remind'],
        );
    }
}
