<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\AppearanceEvent;
use Carbon\CarbonInterface;

/**
 * A look as a visitor sees it: the palette, the pictures and the welcome banners of the newest published version (or
 * the factory look), with today's event laid over it when one is meant for them. A plain value, so it can be cached and
 * handed to a page, a stylesheet or the API.
 */
final class AppearanceView
{
    /**
     * @param  array{light: array<string, string>, dark: array<string, string>}  $tokens
     * @param  array<string, string>  $images  slot => asset id
     * @param  array<string, array<string, mixed>>  $banners  surface => banner as saved
     * @param  array<string, array{0: int, 1: int}>  $sizes  slot => [width, height] of its picture
     * @param  array<string, mixed>  $pins  the colours the published version chose (an event's are laid over them)
     * @param  array{id: string, name: string, revision: int, ends_on: string, until: string, colours: bool}|null  $event  the event worn, if any
     */
    public function __construct(
        private readonly int $version,
        private readonly array $tokens,
        private readonly bool $custom,
        private readonly array $images,
        private readonly array $banners,
        private readonly array $sizes = [],
        private readonly array $pins = [],
        private readonly ?array $event = null,
    ) {}

    public static function factory(): self
    {
        return new self(0, ThemeEngine::BASE, false, [], []);
    }

    /** 0 for the factory look, then 1, 2, 3 for each published version. */
    public function version(): int
    {
        return $this->version;
    }

    /** @return array{light: array<string, string>, dark: array<string, string>} */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /** Whether any colour differs from the factory look (when not, nothing needs to be sent to a page). */
    public function hasCustomColours(): bool
    {
        return $this->custom;
    }

    /** @return array<string, mixed> */
    public function pins(): array
    {
        return $this->pins;
    }

    /**
     * The event this look is wearing: which one, its revision, its last day and the moment it ends (UTC), and whether
     * it changes the colours. Null for the usual look.
     *
     * @return array{id: string, name: string, revision: int, ends_on: string, until: string, colours: bool}|null
     */
    public function event(): ?array
    {
        return $this->event;
    }

    /**
     * The query of this look's stylesheet address: the published version, and the event and its revision when the
     * event has colours of its own. A new publish or an edited event changes the address, so nobody keeps a stale one.
     *
     * @return array{v: int, e?: string}
     */
    public function cssQuery(): array
    {
        $query = ['v' => $this->version];

        if ($this->event !== null && $this->event['colours']) {
            $query['e'] = $this->event['id'].'.'.$this->event['revision'];
        }

        return $query;
    }

    /**
     * This look dressed for [$event] on [$surface]: its colours (already laid over this look's and repaired), its
     * pictures over this look's, and its banner for this place when it has one switched on.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>}  $tokens
     * @param  array<string, array{0: int, 1: int}>  $sizes
     */
    public function withEvent(AppearanceEvent $event, string $surface, array $tokens, array $sizes): self
    {
        $colours = ($event->pins()['light'] ?? []) !== [];
        $banners = $this->banners;
        $banner = $event->banners()[$surface] ?? null;

        if (is_array($banner) && ($banner['enabled'] ?? false)) {
            // The event's days decide when it shows, not dates of its own.
            $banners[$surface] = array_replace($banner, ['starts_on' => null, 'ends_on' => null]);
        }

        return new self(
            $this->version,
            $colours ? $tokens : $this->tokens,
            $this->custom || $colours,
            array_replace($this->images, $event->images()),
            $banners,
            array_replace($this->sizes, $sizes),
            $this->pins,
            [
                'id' => $event->id, 'name' => $event->name, 'revision' => $event->revision,
                'ends_on' => $event->ends_on->toDateString(), 'until' => $event->until()->toIso8601String(), 'colours' => $colours,
            ],
        );
    }

    /** The address of the picture in this slot, or null when there is none. */
    public function imageUrl(string $slot): ?string
    {
        $id = $this->images[$slot] ?? null;

        return is_string($id) && $id !== '' ? route('brand.asset', ['asset' => $id]) : null;
    }

    /**
     * The width and height of the picture in this slot, so a page can keep its place while it loads.
     *
     * @return array{0: int, 1: int}|null
     */
    public function imageSize(string $slot): ?array
    {
        return $this->imageUrl($slot) !== null ? ($this->sizes[$slot] ?? null) : null;
    }

    /** @return array<string, string> */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * The welcome banner for a place and a language, or null when there is none to show today. A language with no
     * title of its own shows the English words.
     *
     * @return array{title: string, message: string, cta_label: string, cta_url: string|null, tone: string, dismissible: bool, image_url: string|null, key: string}|null
     */
    public function banner(string $surface, string $language, ?CarbonInterface $now = null): ?array
    {
        $banner = $this->banners[$surface] ?? null;

        if (! is_array($banner) || ! ($banner['enabled'] ?? false)) {
            return null;
        }

        $today = ($now ?? now())->toDateString();

        if ((! empty($banner['starts_on']) && $today < $banner['starts_on']) || (! empty($banner['ends_on']) && $today > $banner['ends_on'])) {
            return null;
        }

        $text = $banner['text'][$language] ?? null;
        $text = is_array($text) && ($text['title'] ?? '') !== '' ? $text : ($banner['text']['en'] ?? null);

        if (! is_array($text) || ($text['title'] ?? '') === '') {
            return null;
        }

        $label = (string) ($text['cta_label'] ?? '');
        $url = $banner['cta_url'] ?? null;

        return [
            'title' => (string) $text['title'],
            'message' => (string) ($text['message'] ?? ''),
            'cta_label' => $label,
            'cta_url' => $label !== '' && is_string($url) && $url !== '' ? $url : null,
            'tone' => (string) ($banner['tone'] ?? 'gold'),
            'dismissible' => (bool) ($banner['dismissible'] ?? true),
            'image_url' => ($banner['image'] ?? false) ? $this->imageUrl('banner') : null,
            // Remembers a dismissal: it changes when the banner's words, dates or look change, and not otherwise.
            'key' => substr(sha1((string) json_encode($banner)), 0, 12),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['version' => $this->version, 'tokens' => $this->tokens, 'custom' => $this->custom, 'images' => $this->images, 'banners' => $this->banners, 'sizes' => $this->sizes, 'pins' => $this->pins];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        // 'sizes' and 'pins' came later than the rest: a look cached before them simply has none.
        return new self((int) $data['version'], $data['tokens'], (bool) $data['custom'], $data['images'], $data['banners'], $data['sizes'] ?? [], $data['pins'] ?? []);
    }
}
