<?php

namespace App\Notifications\Push;

/**
 * What a phone shows for one alert: a title, a line, and where tapping it leads in the app. A silent message shows
 * nothing: it only wakes the app, with its type to say why (see silent()).
 */
final readonly class PushMessage
{
    /** @param  array<string, string>  $data  plain strings only, as Firebase requires */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
        public bool $silent = false,
    ) {}

    /**
     * A data-only push: no title, no banner, no inbox entry. The app reads the type and refreshes what it keeps.
     *
     * @param  array<string, string>  $data  plain strings only, as Firebase requires
     */
    public static function silent(string $type, array $data = []): self
    {
        return new self($type, '', '', $data, true);
    }
}
