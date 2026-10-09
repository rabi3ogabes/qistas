<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\AppearanceAsset;
use App\Models\AppearanceVersion;
use App\Models\User;
use App\Support\Audit;
use App\Support\Locale;
use App\Theme\Concerns\ManagesEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The look of the product, and the only writer of it: one working draft, and published versions that are never changed.
 * What an admin types here ends up in a stylesheet and on pages, so everything is checked on the way in (colours must be
 * #RRGGBB, links must be the site's own pages or https, words are plain text) and the palette is run through the theme
 * engine's contrast gate on the way out: a look that cannot be made readable is refused, one that can is repaired and the
 * admin is told what moved.
 */
final class Appearance
{
    use ManagesEvents;

    /** The three places a welcome banner can appear. */
    public const SURFACES = ['website', 'webapp', 'mobile'];

    /** The colours an admin chooses; everything else is derived from them. */
    public const COLOURS = ['primary', 'accent', 'info', 'bg'];

    public const TONES = ['gold', 'navy', 'sand', 'sky'];

    private const CACHE_KEY = 'appearance.live';

    /** The live look, once read in this request. */
    private ?AppearanceView $live = null;

    /** What everyone sees now: the newest published version, or the factory look. */
    public function live(): AppearanceView
    {
        return $this->live ??= AppearanceView::fromArray(Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build()->toArray()));
    }

    /** The working copy the admin edits (made from what is live the first time). */
    public function draft(): AppearanceVersion
    {
        $draft = AppearanceVersion::query()->where('status', 'draft')->first();

        if ($draft !== null) {
            return $draft;
        }

        $latest = $this->latest();
        $draft = (new AppearanceVersion)->forceFill([
            'status' => 'draft',
            'pins' => $latest?->pins() ?? [],
            'images' => $latest?->images() ?? [],
            'banners' => $latest?->banners() ?? [],
        ]);
        $draft->save();

        return $draft;
    }

    /**
     * Saves what the form sent into the draft. A part that is not sent is left as it was; a colour sent empty goes back
     * to the factory colour, a picture sent empty is removed.
     *
     * @param  array<string, mixed>  $input  ['colours' => [...], 'banners' => [surface => [...]], 'images' => [slot => asset id]]
     *
     * @throws ValidationException
     */
    public function saveDraft(array $input, User $by): AppearanceVersion
    {
        $clean = $this->validated($input);
        $draft = $this->draft();

        if (array_key_exists('colours', $clean)) {
            $draft->pins = $clean['colours'] === [] ? [] : ['light' => $clean['colours']];
        }

        if (array_key_exists('images', $clean)) {
            $draft->images = array_filter(array_replace($draft->images(), $clean['images']), fn ($id) => $id !== null);
        }

        if (array_key_exists('banners', $clean)) {
            $draft->banners = array_replace($draft->banners(), $clean['banners']);
        }

        $draft->created_by_user_id ??= $by->getKey();
        $draft->save();

        return $draft;
    }

    /**
     * Makes the draft what everyone sees, as the next numbered version.
     *
     * @throws AppearanceRefused when some text would still be unreadable after the automatic repair
     */
    public function publish(User $by, ?string $note = null): AppearanceVersion
    {
        $draft = $this->draft();
        $fixed = ThemeEngine::autoFix(ThemeEngine::resolve($draft->pins(), repair: false));
        $failing = array_values(array_filter(ThemeEngine::validate($fixed['tokens']), fn (array $check): bool => ! $check['pass'] && $check['blocking']));

        if ($failing !== []) {
            throw new AppearanceRefused($failing);
        }

        return $this->release($by, 'appearance.published', $note, $draft->pins(), $fixed['tokens'], $fixed['changed'], $draft->images(), $draft->banners());
    }

    /** Puts an older version back, as a new version. History is never rewritten. */
    public function restore(AppearanceVersion $version, User $by): AppearanceVersion
    {
        if ($version->status !== 'published' || $version->tokens === null) {
            throw new InvalidArgumentException('Only a published version can be restored.');
        }

        return $this->release($by, 'appearance.restored', 'Restored version '.$version->version, $version->pins(), $version->tokens, $version->repaired(), $version->images(), $version->banners());
    }

    /** The factory colours and no pictures, as a new version; the welcome banners stay as they are. */
    public function resetLook(User $by): AppearanceVersion
    {
        return $this->release($by, 'appearance.reset', 'Back to the Qistas look', [], ThemeEngine::BASE, [], [], $this->latest()?->banners() ?? []);
    }

    /** Throws away what was not published: the draft becomes what everyone sees again. */
    public function discardDraft(User $by): void
    {
        $latest = $this->latest();
        $draft = $this->draft();
        $draft->forceFill(['pins' => $latest?->pins() ?? [], 'images' => $latest?->images() ?? [], 'banners' => $latest?->banners() ?? []])->save();

        Audit::record('appearance.discarded', $draft, ['version' => $latest?->version], userId: $by->getKey());
    }

    /** Whether the draft holds anything that is not published yet. */
    public function hasUnpublishedChanges(): bool
    {
        $draft = $this->draft();
        $latest = $this->latest();

        return self::fingerprint($draft->pins(), $draft->images(), $draft->banners())
            !== self::fingerprint($latest?->pins() ?? [], $latest?->images() ?? [], $latest?->banners() ?? []);
    }

    /** @return Collection<int, AppearanceVersion> the published versions, newest first */
    public function history(int $limit = 20): Collection
    {
        return AppearanceVersion::query()->where('status', 'published')->orderByDesc('version')->limit($limit)->get();
    }

    /** The same look gives the same string, whatever order its keys were saved in. */
    private static function fingerprint(mixed ...$parts): string
    {
        return (string) json_encode(array_map(self::sorted(...), $parts));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }

    private function latest(): ?AppearanceVersion
    {
        return AppearanceVersion::query()->where('status', 'published')->orderByDesc('version')->first();
    }

    private function build(): AppearanceView
    {
        $latest = $this->latest();

        if ($latest === null) {
            return AppearanceView::factory();
        }

        $sizes = AppearanceAsset::query()->whereKey(array_values($latest->images()))->get(['id', 'slot', 'width', 'height'])
            ->mapWithKeys(fn (AppearanceAsset $asset): array => [$asset->slot => [(int) $asset->width, (int) $asset->height]])->all();

        return new AppearanceView((int) $latest->version, $latest->tokens ?? ThemeEngine::BASE, $latest->pins() !== [], $latest->images(), $latest->banners(), $sizes, $latest->pins());
    }

    /**
     * Writes a new published version, makes the draft match it, writes the audit row and clears the cache. Two
     * publishes at the same moment get different numbers: the number is unique, and the loser tries again.
     *
     * @param  array<string, mixed>  $pins
     * @param  array<string, array<string, string>>  $tokens
     * @param  list<array<string, string>>  $repaired
     * @param  array<string, string>  $images
     * @param  array<string, array<string, mixed>>  $banners
     */
    private function release(User $by, string $action, ?string $note, array $pins, array $tokens, array $repaired, array $images, array $banners): AppearanceVersion
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $row = DB::transaction(function () use ($by, $action, $note, $pins, $tokens, $repaired, $images, $banners): AppearanceVersion {
                    $row = (new AppearanceVersion)->forceFill([
                        'version' => (int) AppearanceVersion::query()->max('version') + 1,
                        'status' => 'published',
                        'pins' => $pins,
                        'tokens' => $tokens,
                        'images' => $images,
                        'banners' => $banners,
                        'repaired' => $repaired === [] ? null : $repaired,
                        'note' => $note === null ? null : mb_substr($note, 0, 200),
                        'created_by_user_id' => $by->getKey(),
                        'published_by_user_id' => $by->getKey(),
                        'published_at' => now(),
                    ]);
                    $row->save();

                    $draft = $this->draft();
                    $draft->forceFill(['pins' => $pins, 'images' => $images, 'banners' => $banners])->save();

                    Audit::record($action, $row, ['version' => $row->version, 'note' => $row->note, 'moved_for_contrast' => count($repaired)], userId: $by->getKey());

                    return $row;
                });

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }

        Cache::forget(self::CACHE_KEY);
        $this->live = null;
        $this->current = [];

        return $row;
    }

    // ------------------------------------------------------------------------------------------ validation

    /**
     * Checks and tidies what the form sent. Returns only the parts that were sent.
     *
     * @param  array<string, mixed>  $input
     * @return array{colours?: array<string, string>, images?: array<string, string|null>, banners?: array<string, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $input = $this->blanksToNull($input);
        $rules = ['colours' => ['sometimes', 'nullable', 'array'], 'banners' => ['sometimes', 'nullable', 'array'], 'images' => ['sometimes', 'nullable', 'array']];
        $messages = [
            'colours.*.regex' => __('Use a colour written like #0B1F44.'),
            'banners.*.text.*.*.regex' => __('Plain words only: no < or > and no line breaks.'),
            'banners.*.text.*.*.max' => __('That is too long.'),
            'banners.*.cta_url.max' => __('That is too long.'),
            'banners.*.ends_on.after_or_equal' => __('The end date must be on or after the start date.'),
            'banners.*.tone.in' => __('Choose one of the tones offered.'),
            'banners.*.starts_on.date_format' => __('Use a date like 2026-12-31.'),
            'banners.*.ends_on.date_format' => __('Use a date like 2026-12-31.'),
        ];

        foreach (self::COLOURS as $name) {
            $rules["colours.{$name}"] = ['nullable', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'];
        }

        $rules['colours.bg'][] = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && Color::isHex($value) && Color::luminance($value) < 0.5) {
                $fail(__('Choose a light canvas: the dark mode is made from your main colour.'));
            }
        };

        $banners = is_array($input['banners'] ?? null) ? $input['banners'] : [];

        foreach ($banners as $surface => $banner) {
            if (! in_array($surface, self::SURFACES, true) || ! is_array($banner)) {
                continue;
            }

            $rules["banners.{$surface}.enabled"] = ['nullable', 'boolean'];
            $rules["banners.{$surface}.dismissible"] = ['nullable', 'boolean'];
            $rules["banners.{$surface}.image"] = ['nullable', 'boolean'];
            $rules["banners.{$surface}.tone"] = ['nullable', 'string', 'in:'.implode(',', self::TONES)];
            $rules["banners.{$surface}.starts_on"] = ['nullable', 'date_format:Y-m-d'];
            $rules["banners.{$surface}.ends_on"] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:banners.'.$surface.'.starts_on'];
            $rules["banners.{$surface}.cta_url"] = ['nullable', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! self::safeLink($value)) {
                    $fail(__('Use a page of this site (starting with /) or a secure address (https://).'));
                }
            }];
            $rules["banners.{$surface}.text"] = ['nullable', 'array'];

            foreach (['title' => 80, 'message' => 240, 'cta_label' => 30] as $field => $max) {
                $rules["banners.{$surface}.text.*.{$field}"] = ['nullable', 'string', "max:{$max}", 'regex:/\A[^<>\x00-\x1F\x7F]*\z/u'];
            }
        }

        $images = is_array($input['images'] ?? null) ? $input['images'] : [];

        foreach ($images as $slot => $id) {
            if (! array_key_exists($slot, BrandImages::SLOTS)) {
                continue;
            }

            $rules["images.{$slot}"] = ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($slot): void {
                if (is_string($value) && ! AppearanceAsset::query()->whereKey($value)->where('slot', $slot)->exists()) {
                    $fail(__('Choose a picture that was uploaded for this place.'));
                }
            }];
        }

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function ($validator) use ($input, $banners, $images): void {
            $colours = is_array($input['colours'] ?? null) ? $input['colours'] : [];

            if (array_diff(array_keys($colours), self::COLOURS) !== []) {
                $validator->errors()->add('colours', __('Only the four brand colours can be chosen.'));
            }

            if (array_diff(array_keys($banners), self::SURFACES) !== []) {
                $validator->errors()->add('banners', __('A banner belongs to the website, the web app or the Android app.'));
            }

            if (array_diff(array_keys($images), array_keys(BrandImages::SLOTS)) !== []) {
                $validator->errors()->add('images', __('There is no such place for a picture.'));
            }

            foreach ($banners as $surface => $banner) {
                if (! in_array($surface, self::SURFACES, true) || ! is_array($banner)) {
                    continue;
                }

                $text = is_array($banner['text'] ?? null) ? $banner['text'] : [];

                if (array_diff(array_keys($text), array_keys(Locale::options())) !== []) {
                    $validator->errors()->add("banners.{$surface}.text", __('The words must be in one of our five languages.'));
                }

                if (! empty($banner['enabled']) && trim((string) ($text['en']['title'] ?? '')) === '') {
                    $validator->errors()->add("banners.{$surface}.text.en.title", __('Write the title in English first; other languages use it when they have no words of their own.'));
                }
            }
        });

        $validator->validate();

        return $this->tidy($input);
    }

    /**
     * The checked input in the shape it is stored in.
     *
     * @param  array<string, mixed>  $input
     * @return array{colours?: array<string, string>, images?: array<string, string|null>, banners?: array<string, array<string, mixed>>}
     */
    private function tidy(array $input): array
    {
        $out = [];

        if (array_key_exists('colours', $input) && is_array($input['colours'])) {
            $out['colours'] = [];

            foreach (self::COLOURS as $name) {
                $value = $input['colours'][$name] ?? null;

                if (is_string($value) && $value !== '') {
                    $out['colours'][$name] = Color::normalise($value);
                }
            }
        } elseif (array_key_exists('colours', $input)) {
            $out['colours'] = [];
        }

        if (isset($input['images']) && is_array($input['images'])) {
            $out['images'] = [];

            foreach ($input['images'] as $slot => $id) {
                $out['images'][$slot] = is_string($id) && $id !== '' ? $id : null;
            }
        }

        if (isset($input['banners']) && is_array($input['banners'])) {
            $out['banners'] = [];

            foreach ($input['banners'] as $surface => $banner) {
                $text = [];

                foreach ((array) ($banner['text'] ?? []) as $language => $words) {
                    $words = array_filter(array_map(fn ($value) => trim((string) $value), array_intersect_key((array) $words, array_flip(['title', 'message', 'cta_label']))), fn ($value) => $value !== '');

                    if ($words !== []) {
                        $text[$language] = $words;
                    }
                }

                $out['banners'][$surface] = [
                    'enabled' => (bool) ($banner['enabled'] ?? false),
                    'tone' => (string) ($banner['tone'] ?? 'gold'),
                    'dismissible' => (bool) ($banner['dismissible'] ?? true),
                    'image' => (bool) ($banner['image'] ?? false),
                    'starts_on' => $banner['starts_on'] ?? null,
                    'ends_on' => $banner['ends_on'] ?? null,
                    'cta_url' => $banner['cta_url'] ?? null,
                    'text' => $text,
                ];
            }
        }

        return $out;
    }

    /** A call-to-action may only lead to a page of this site ("/pricing") or to a secure address. */
    private static function safeLink(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return true; // no link is allowed
        }

        if (preg_match('/\A\/(?![\/\\\\])[^\s\\\\<>"\x00-\x1F\x7F]*\z/', $url) === 1) {
            return true;
        }

        return preg_match('/\Ahttps:\/\/[^\s\\\\<>"\x00-\x1F\x7F]+\z/i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * An empty string from a form is "nothing chosen".
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function blanksToNull(array $value): array
    {
        return array_map(fn ($item) => is_array($item) ? $this->blanksToNull($item) : ($item === '' ? null : $item), $value);
    }
}
