<?php

declare(strict_types=1);

namespace App\Theme\Concerns;

use App\Models\AppearanceAsset;
use App\Models\AppearanceEvent;
use App\Models\User;
use App\Support\Audit;
use App\Theme\AppearanceRefused;
use App\Theme\AppearanceView;
use App\Theme\ThemeEngine;
use App\Theme\Visitor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Event themes, part of App\Theme\Appearance: a national day's or a season's look for some countries and some days,
 * laid over the published look while it lasts. Checked like the look itself on the way in (plain words, safe links,
 * #RRGGBB colours, a light canvas) and through the same contrast repair on the way out.
 */
trait ManagesEvents
{
    private const EVENTS_CACHE_KEY = 'appearance.events';

    /** The longest an event may last: a little over a year covers any season. */
    private const EVENT_MAX_DAYS = 400;

    /**
     * The look for each place, once worked out for a request (keyed by the request itself, so a later request, in a
     * long-running worker or a test, never sees an earlier visitor's look).
     *
     * @var \WeakMap<Request, array<string, AppearanceView>>|null
     */
    private ?\WeakMap $current = null;

    /**
     * What this request's visitor sees on [$surface] (by default the place the request is for: /app is the web app, the
     * API is the Android app, anything else the website): the live look, with today's event for their country if any.
     */
    public function current(Request $request, ?string $surface = null): AppearanceView
    {
        $surface ??= match (true) {
            $request->is('app', 'app/*') => 'webapp',
            $request->is('api/*') => 'mobile',
            default => 'website',
        };

        $this->current ??= new \WeakMap;
        $looks = $this->current[$request] ?? [];

        if (! isset($looks[$surface])) {
            $looks[$surface] = $this->lookFor(Visitor::country($request), $surface);
            $this->current[$request] = $looks;
        }

        return $looks[$surface];
    }

    /** The look a visitor from [$country] sees on [$surface] at [$now]. */
    public function lookFor(?string $country, string $surface, ?CarbonInterface $now = null): AppearanceView
    {
        $base = $this->live();
        $event = $this->activeEvent($country, $surface, $now ?? now());

        if ($event === null) {
            return $base;
        }

        $dressed = $this->dressing($event);

        return $base->withEvent($event, $surface, $dressed['tokens'], $dressed['sizes']);
    }

    /**
     * The event a visitor from [$country] sees on [$surface] at [$now]: of those on now, the one aimed at fewer
     * countries, then the one that started later, then the one changed last.
     */
    public function activeEvent(?string $country, string $surface, CarbonInterface $now): ?AppearanceEvent
    {
        return $this->scheduledEvents()
            ->filter(fn (AppearanceEvent $event): bool => $event->isOnAt($now) && $event->appliesTo($country, $surface))
            ->sort(function (AppearanceEvent $a, AppearanceEvent $b): int {
                $reach = fn (AppearanceEvent $e): int => $e->countries === [] ? PHP_INT_MAX : count($e->countries);

                return [$reach($a), $b->starts_on->toDateString(), (string) $b->updated_at] <=> [$reach($b), $a->starts_on->toDateString(), (string) $a->updated_at];
            })
            ->first();
    }

    /** @return Collection<int, AppearanceEvent> every event, the next to come first */
    public function events(): Collection
    {
        return AppearanceEvent::query()->with('updatedBy:id,name')->orderByDesc('ends_on')->orderBy('starts_on')->get();
    }

    /**
     * Creates an event, or changes the one given (only what is sent changes). A scheduled event keeps showing and is
     * checked for readability again; its revision moves on so pages fetch its new stylesheet.
     *
     * @param  array<string, mixed>  $input  name, countries, starts_on, ends_on, timezone, surfaces, colours, images, banners, preset
     *
     * @throws ValidationException
     * @throws AppearanceRefused
     */
    public function saveEvent(?AppearanceEvent $event, array $input, User $by): AppearanceEvent
    {
        $data = array_replace($event === null ? [] : $this->eventInput($event), $input);
        $look = $this->validatedEvent($data);

        return DB::transaction(function () use ($event, $data, $look, $by): AppearanceEvent {
            $event ??= (new AppearanceEvent)->forceFill(['status' => 'draft', 'revision' => 0, 'created_by_user_id' => $by->getKey()]);
            $event->forceFill([
                'name' => trim((string) $data['name']),
                'preset' => is_string($data['preset'] ?? null) ? $data['preset'] : $event->preset,
                'countries' => array_values(array_unique(array_map('strtoupper', (array) $data['countries']))),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'timezone' => $data['timezone'],
                'surfaces' => array_values(array_unique((array) $data['surfaces'])),
                'pins' => ($look['colours'] ?? []) === [] ? [] : ['light' => $look['colours']],
                'images' => array_filter($look['images'] ?? [], fn ($id) => $id !== null),
                'banners' => $look['banners'] ?? [],
                'revision' => $event->revision + 1,
                'updated_by_user_id' => $by->getKey(),
            ]);

            if ($event->status === 'scheduled') {
                $event->repaired = $this->checkReadable($event) ?: null;
            }

            $event->save();
            Audit::record('appearance.event_saved', $event, ['name' => $event->name, 'status' => $event->status, 'revision' => $event->revision], userId: $by->getKey());
            $this->forgetEvents();

            return $event;
        });
    }

    /**
     * Puts an event on the calendar: it shows on its days. The colours go through the contrast repair first, and what
     * moved is kept to tell the admin.
     *
     * @throws AppearanceRefused
     */
    public function scheduleEvent(AppearanceEvent $event, User $by): AppearanceEvent
    {
        $repaired = $this->checkReadable($event);
        $event->forceFill(['status' => 'scheduled', 'repaired' => $repaired ?: null, 'revision' => $event->revision + 1, 'updated_by_user_id' => $by->getKey()])->save();

        Audit::record('appearance.event_scheduled', $event, ['name' => $event->name, 'starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString(), 'countries' => $event->countries, 'moved_for_contrast' => count($repaired)], userId: $by->getKey());
        $this->forgetEvents();

        return $event;
    }

    /** Takes an event off the calendar at once; it stays as a draft. */
    public function stopEvent(AppearanceEvent $event, User $by): AppearanceEvent
    {
        $event->forceFill(['status' => 'draft', 'revision' => $event->revision + 1, 'updated_by_user_id' => $by->getKey()])->save();

        Audit::record('appearance.event_stopped', $event, ['name' => $event->name], userId: $by->getKey());
        $this->forgetEvents();

        return $event;
    }

    public function deleteEvent(AppearanceEvent $event, User $by): void
    {
        Audit::record('appearance.event_deleted', $event, ['name' => $event->name, 'status' => $event->status], userId: $by->getKey());
        $event->delete();
        $this->forgetEvents();
    }

    /**
     * The palette of an event over the live look (its colours laid over the live look's own, repaired), and the sizes
     * of its pictures. Kept until the live look or the event changes.
     *
     * @return array{tokens: array{light: array<string, string>, dark: array<string, string>}, sizes: array<string, array{0: int, 1: int}>}
     */
    public function dressing(AppearanceEvent $event): array
    {
        $base = $this->live();

        return Cache::rememberForever("appearance.event.{$base->version()}.{$event->id}.{$event->revision}", function () use ($base, $event): array {
            $pins = ($event->pins()['light'] ?? []) === [] ? [] : ['light' => array_replace($base->pins()['light'] ?? [], $event->pins()['light'])];
            $sizes = AppearanceAsset::query()->whereKey(array_values($event->images()))->get(['id', 'slot', 'width', 'height'])
                ->mapWithKeys(fn (AppearanceAsset $asset): array => [$asset->slot => [(int) $asset->width, (int) $asset->height]])->all();

            return ['tokens' => $pins === [] ? $base->tokens() : ThemeEngine::resolve($pins), 'sizes' => $sizes];
        });
    }

    /** @return Collection<int, AppearanceEvent> */
    private function scheduledEvents(): Collection
    {
        $rows = Cache::rememberForever(self::EVENTS_CACHE_KEY, fn (): array => AppearanceEvent::query()->where('status', 'scheduled')->get()->map->getAttributes()->all());

        return AppearanceEvent::hydrate($rows);
    }

    private function forgetEvents(): void
    {
        Cache::forget(self::EVENTS_CACHE_KEY);
        $this->current = null;
    }

    /**
     * Runs the event's colours, over the live look's, through the contrast repair; refuses what cannot be made
     * readable and returns what moved.
     *
     * @return list<array{mode: string, token: string, from: string, to: string}>
     *
     * @throws AppearanceRefused
     */
    private function checkReadable(AppearanceEvent $event): array
    {
        if (($event->pins()['light'] ?? []) === []) {
            return [];
        }

        $pins = ['light' => array_replace($this->live()->pins()['light'] ?? [], $event->pins()['light'])];
        $fixed = ThemeEngine::autoFix(ThemeEngine::resolve($pins, repair: false));
        $failing = array_values(array_filter(ThemeEngine::validate($fixed['tokens']), fn (array $check): bool => ! $check['pass'] && $check['blocking']));

        if ($failing !== []) {
            throw new AppearanceRefused($failing);
        }

        return $fixed['changed'];
    }

    /**
     * An event as the editor's form holds it.
     *
     * @return array<string, mixed>
     */
    private function eventInput(AppearanceEvent $event): array
    {
        return [
            'name' => $event->name,
            'preset' => $event->preset,
            'countries' => $event->countries,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'timezone' => $event->timezone,
            'surfaces' => $event->surfaces,
            'colours' => $event->pins()['light'] ?? [],
            'images' => $event->images(),
            'banners' => $event->banners(),
        ];
    }

    /**
     * Checks an event: its own fields here, its colours, pictures and banners by the look's own rules. Every mistake
     * is reported at once.
     *
     * @param  array<string, mixed>  $data
     * @return array{colours?: array<string, string>, images?: array<string, string|null>, banners?: array<string, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validatedEvent(array $data): array
    {
        $errors = new MessageBag;
        $look = [];

        try {
            $look = $this->validated(array_intersect_key($data, array_flip(['colours', 'images', 'banners'])));
        } catch (ValidationException $e) {
            $errors->merge($e->errors());
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:80', 'regex:/\A[^<>\x00-\x1F\x7F]*\z/u'],
            'countries' => ['present', 'array'],
            'countries.*' => ['string', Rule::in(array_keys((array) config('qistas.countries')))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'surfaces' => ['required', 'array', 'min:1'],
            'surfaces.*' => ['string', Rule::in(self::SURFACES)],
        ], [
            'name.regex' => __('Plain words only: no < or > and no line breaks.'),
            'countries.*.in' => __('Choose countries from the list.'),
            'ends_on.after_or_equal' => __('The last day must be on or after the first day.'),
            'starts_on.date_format' => __('Use a date like 2026-12-31.'),
            'ends_on.date_format' => __('Use a date like 2026-12-31.'),
            'timezone.in' => __('Choose a time zone from the list.'),
            'surfaces.required' => __('Choose at least one place for the event.'),
            'surfaces.*.in' => __('An event dresses the website, the web app or the Android app.'),
        ]);

        $validator->after(function ($validator) use ($data): void {
            $day = fn (mixed $value): ?CarbonImmutable => is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null : null;
            $start = $day($data['starts_on'] ?? null);
            $end = $day($data['ends_on'] ?? null);

            if ($start && $end && $start->diffInDays($end) + 1 > self::EVENT_MAX_DAYS) {
                $validator->errors()->add('ends_on', __('An event can last :days days at most.', ['days' => self::EVENT_MAX_DAYS]));
            }
        });

        if ($validator->fails()) {
            $errors->merge($validator->errors());
        }

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return $look;
    }
}
