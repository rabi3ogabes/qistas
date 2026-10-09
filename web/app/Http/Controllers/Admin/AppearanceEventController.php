<?php

namespace App\Http\Controllers\Admin;

use App\Models\AppearanceAsset;
use App\Models\AppearanceEvent;
use App\Models\User;
use App\Support\Countries;
use App\Support\Locale;
use App\Theme\Appearance;
use App\Theme\AppearanceRefused;
use App\Theme\BrandImages;
use App\Theme\EventPresets;
use App\Theme\PaletteReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Event themes in the admin: the editor of one event (its countries, days, places, colours, pictures and banners, with a
 * live preview), and "Preview as" a country on a date. Anyone on the platform team may look; only a super admin
 * changes (the 'manage-appearance' gate). Every write goes through App\Theme\Appearance.
 */
final class AppearanceEventController
{
    public function __construct(
        private readonly Appearance $appearance,
        private readonly BrandImages $images,
    ) {}

    public function create(Request $request): View
    {
        Gate::authorize('manage-appearance');
        $preset = $request->query('preset');
        $draft = is_string($preset) && array_key_exists($preset, EventPresets::LIST) ? EventPresets::draft($preset) : [
            'name' => '', 'countries' => [], 'surfaces' => Appearance::SURFACES, 'colours' => [], 'banners' => [],
            'starts_on' => now('Asia/Riyadh')->toDateString(), 'ends_on' => now('Asia/Riyadh')->toDateString(), 'timezone' => 'Asia/Riyadh',
        ];

        return $this->editor(null, $draft);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $event = $this->appearance->saveEvent(null, $this->input($request), $this->admin($request));

        return $this->afterSave($request, $event, created: true);
    }

    public function edit(AppearanceEvent $event): View
    {
        return $this->editor($event, []);
    }

    public function update(Request $request, AppearanceEvent $event): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $wasScheduled = $event->status === 'scheduled';

        try {
            $event = $this->appearance->saveEvent($event, $this->input($request), $this->admin($request));
        } catch (AppearanceRefused $e) {
            return back()->withInput()->withErrors(['schedule' => $this->refusal($e)]);
        }

        return $this->afterSave($request, $event, created: false, wasScheduled: $wasScheduled);
    }

    public function stop(Request $request, AppearanceEvent $event): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $this->appearance->stopEvent($event, $this->admin($request));

        return redirect()->route('admin.appearance.events.edit', $event)
            ->with('status', __('Stopped. :event is a draft again; nobody sees it.', ['event' => $event->name]));
    }

    public function destroy(Request $request, AppearanceEvent $event): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $name = $event->name;
        $this->appearance->deleteEvent($event, $this->admin($request));

        return redirect()->to(route('admin.appearance.index').'#events')->with('status', __(':event was deleted.', ['event' => $name]));
    }

    /** A picture for an event, sent by the page's script as soon as it is chosen; the event keeps it when saved. */
    public function picture(Request $request, string $slot): JsonResponse
    {
        Gate::authorize('manage-appearance');
        abort_unless(array_key_exists($slot, BrandImages::SLOTS), 404);
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        $asset = $this->images->store($file instanceof UploadedFile ? $file : throw ValidationException::withMessages(['file' => __('Choose one picture.')]), $slot, $this->admin($request));

        return response()->json(['data' => ['id' => $asset->id, 'slot' => $slot, 'url' => $asset->url(), 'width' => $asset->width, 'height' => $asset->height]], 201);
    }

    /** What a visitor from a country sees on a date, on a place: for the Appearance page's "Preview as". */
    public function look(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', Rule::in(array_keys((array) config('qistas.countries')))],
            'date' => ['required', 'date_format:Y-m-d'],
            'surface' => ['required', Rule::in(Appearance::SURFACES)],
        ]);
        $country = $data['country'] ?? null;
        $zone = (string) config('qistas.timezones.'.($country ?? ''), 'UTC');
        $look = $this->appearance->lookFor($country, $data['surface'], CarbonImmutable::parse($data['date'].' 12:00:00', $zone));

        return response()->json(['data' => [
            'tokens' => $look->tokens(),
            'event' => $look->event() === null ? null : ['id' => $look->event()['id'], 'name' => $look->event()['name']],
            'banner' => $look->banner($data['surface'], app()->getLocale(), CarbonImmutable::parse($data['date'].' 12:00:00', $zone)),
            'logo_url' => $look->imageUrl('logo'),
            'logo_dark_url' => $look->imageUrl('logo_dark'),
            'hero_url' => $look->imageUrl('hero'),
            'banner_picture_url' => $look->imageUrl('banner'),
        ]]);
    }

    /**
     * The editor: the event's fields (or a new event's), what its colours would become over the live look, and its
     * preview with its pictures and banners over the usual ones.
     *
     * @param  array<string, mixed>  $draft  the starting fields of a new event
     */
    private function editor(?AppearanceEvent $event, array $draft): View
    {
        $live = $this->appearance->live();
        $fields = $event === null ? $draft : [
            'name' => $event->name, 'countries' => $event->countries, 'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(), 'timezone' => $event->timezone, 'surfaces' => $event->surfaces,
            'colours' => $event->pins()['light'] ?? [], 'banners' => $event->banners(), 'images' => $event->images(), 'preset' => $event->preset,
        ];
        $baseColours = $live->pins()['light'] ?? [];
        $chosen = (array) ($fields['colours'] ?? []);
        $images = array_replace($live->images(), (array) ($fields['images'] ?? []));
        $baseTokens = $live->tokens()['light'];

        return view('admin.appearance.event', [
            'event' => $event,
            'form' => $fields,
            'canChange' => Gate::allows('manage-appearance'),
            'chosen' => $chosen,
            'baseColours' => $baseColours,
            'report' => PaletteReport::for(array_replace($baseColours, $chosen)),
            // An empty colour keeps the usual one: the field shows it as its placeholder.
            'factory' => array_combine(Appearance::COLOURS, array_map(fn (string $name): string => $baseTokens[$name], Appearance::COLOURS)),
            // The preview shows the event's pictures over the usual ones; each slot shows the event's own.
            'pictures' => AppearanceAsset::query()->whereKey(array_values($images))->get(['id', 'slot', 'width', 'height'])->keyBy('slot'),
            'slotPictures' => AppearanceAsset::query()->whereKey(array_values((array) ($fields['images'] ?? [])))->get(['id', 'slot', 'width', 'height'])->keyBy('slot'),
            'banners' => (array) ($fields['banners'] ?? []),
            'previewBanners' => array_replace($live->toArray()['banners'], array_filter((array) ($fields['banners'] ?? []), fn ($banner) => is_array($banner) && ($banner['enabled'] ?? false))),
            'languages' => Locale::options(),
            'countries' => Countries::options(),
            'zones' => array_values(array_unique(array_merge(array_values((array) config('qistas.timezones')), ['UTC']))),
            'live' => $live,
            'unpublished' => false,
        ]);
    }

    /**
     * The form as the store takes it: "Everyone" empties the countries, a picture marked for removal goes, and a
     * picture sent with the form (no script) is stored first.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = $request->only(['name', 'starts_on', 'ends_on', 'timezone', 'colours', 'banners']);
        $input['countries'] = $request->boolean('everyone') ? [] : array_values(array_filter((array) $request->input('countries', []), 'is_string'));
        $input['surfaces'] = array_values(array_filter((array) $request->input('surfaces', []), 'is_string'));
        $images = array_map(fn ($id) => is_string($id) ? $id : '', (array) $request->input('images', []));

        foreach (array_keys(BrandImages::SLOTS) as $slot) {
            if ($request->boolean("remove.{$slot}")) {
                $images[$slot] = '';
            }

            $file = $request->file("pictures.{$slot}");

            if ($file instanceof UploadedFile) {
                try {
                    $images[$slot] = $this->images->store($file, $slot, $this->admin($request))->id;
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["pictures.{$slot}" => $e->errors()['file'] ?? [__('That picture could not be used.')]]);
                }
            }
        }

        $input['images'] = array_intersect_key($images, BrandImages::SLOTS);

        if (is_string($request->input('preset')) && array_key_exists($request->input('preset'), EventPresets::LIST)) {
            $input['preset'] = $request->input('preset');
        }

        return $input;
    }

    private function afterSave(Request $request, AppearanceEvent $event, bool $created, bool $wasScheduled = false): RedirectResponse
    {
        $to = redirect()->route('admin.appearance.events.edit', $event);

        if ($request->input('action') === 'schedule' && $event->status !== 'scheduled') {
            try {
                $event = $this->appearance->scheduleEvent($event, $this->admin($request));
            } catch (AppearanceRefused $e) {
                return $to->withErrors(['schedule' => $this->refusal($e)]);
            }

            $where = $event->countries === [] ? __('for everyone') : __('in :countries', ['countries' => implode(', ', array_map(fn (string $c): string => Countries::options()[$c] ?? $c, $event->countries))]);

            return $to->with('status', __('Scheduled: :event shows from :from to :to :where.', [
                'event' => $event->name, 'from' => $event->starts_on->translatedFormat('j M Y'), 'to' => $event->ends_on->translatedFormat('j M Y'), 'where' => $where,
            ]))->with('repaired', $event->repaired ?? []);
        }

        if ($wasScheduled) {
            return $to->with('status', __('Saved. Visitors in its countries see the change at once.'))->with('repaired', $event->repaired ?? []);
        }

        return $to->with('status', $created || $event->status === 'draft' ? __('Saved as a draft. Nobody sees it until you schedule it.') : __('Saved.'));
    }

    private function refusal(AppearanceRefused $e): string
    {
        return __('Not scheduled: some words would still be hard to read (:pairs).', ['pairs' => implode(', ', array_map(fn (array $check): string => __($check['label']), $e->failing))]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
