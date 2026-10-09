<?php

declare(strict_types=1);

namespace App\Theme;

use Carbon\CarbonInterface;

/**
 * The look everyone sees right now: the palette, the pictures and the welcome banners of the newest published version
 * (or the factory look). A plain value, so it can be cached and handed to a page, a stylesheet or the API.
 */
final class AppearanceView
{
    /**
     * @param  array{light: array<string, string>, dark: array<string, string>}  $tokens
     * @param  array<string, string>  $images  slot => asset id
     * @param  array<string, array<string, mixed>>  $banners  surface => banner as saved
     * @param  array<string, array{0: int, 1: int}>  $sizes  slot => [width, height] of its picture
     */
    public function __construct(
        private readonly int $version,
        private readonly array $tokens,
        private readonly bool $custom,
        private readonly array $images,
        private readonly array $banners,
        private readonly array $sizes = [],
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
        return ['version' => $this->version, 'tokens' => $this->tokens, 'custom' => $this->custom, 'images' => $this->images, 'banners' => $this->banners, 'sizes' => $this->sizes];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        // 'sizes' came later than the rest: a look cached before it simply has none.
        return new self((int) $data['version'], $data['tokens'], (bool) $data['custom'], $data['images'], $data['banners'], $data['sizes'] ?? []);
    }
}
