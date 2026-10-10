<?php

namespace App\Notifications\Push;

/** What a phone shows for one alert: a title, a line, and where tapping it leads in the app. */
final readonly class PushMessage
{
    /** @param  array<string, string>  $data  plain strings only, as Firebase requires */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}
}
