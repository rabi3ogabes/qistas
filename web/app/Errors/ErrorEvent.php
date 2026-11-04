<?php

declare(strict_types=1);

namespace App\Errors;

use InvalidArgumentException;

/**
 * One thing that went wrong, as the error center sees it before it is stored: what, where, and on which version. A plain
 * value object with no database in it, so a hook, a controller, a command or the spool can all build and read one.
 *
 * It carries what the design allows (section 6) and nothing else: no request body, header, cookie, address or user
 * agent. `ErrorScrubber::event()` is the only way text gets cleaned, and the recorder calls it before anything is stored.
 *
 * `userId` is the one field that is not text to clean: it is read once, to count how many different people an issue
 * touched (as a short HMAC, never the id itself), and is not stored.
 *
 * `toArray()` / `fromArray()` are the shape of one line in the spool file, so `fromArray()` is forgiving about a line
 * that was written by an older or damaged process: a field of the wrong type falls back to its default, an unknown field
 * is ignored, and only a line without a source and a kind is refused.
 */
final class ErrorEvent
{
    /** ISO 8601 with the offset, when it happened (now, unless told). */
    public readonly string $at;

    /**
     * @param  string  $source  site, api, database or app
     * @param  string  $kind  exception, http, slow_query, script or crash
     * @param  string  $class  the exception or error class; '' when there is none (an error page)
     * @param  string  $platform  server, web, android or ios
     * @param  string|null  $device  desktop, mobile or tablet
     */
    public function __construct(
        public readonly string $source,
        public readonly string $kind,
        public readonly string $class = '',
        public readonly string $message = '',
        public readonly string $trace = '',
        public readonly ?int $status = null,
        public readonly ?string $method = null,
        public readonly ?string $route = null,
        public readonly ?string $version = null,
        public readonly string $platform = 'server',
        public readonly ?string $device = null,
        public readonly ?string $userId = null,
        ?string $at = null,
    ) {
        $this->at = $at === null || $at === '' ? now()->toIso8601String() : $at;
    }

    /**
     * One line of the spool, field by field.
     *
     * @return array{source: string, kind: string, class: string, message: string, trace: string, status: int|null, method: string|null, route: string|null, version: string|null, platform: string, device: string|null, user_id: string|null, at: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'kind' => $this->kind,
            'class' => $this->class,
            'message' => $this->message,
            'trace' => $this->trace,
            'status' => $this->status,
            'method' => $this->method,
            'route' => $this->route,
            'version' => $this->version,
            'platform' => $this->platform,
            'device' => $this->device,
            'user_id' => $this->userId,
            'at' => $this->at,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when the line has no source or no kind
     */
    public static function fromArray(array $data): self
    {
        $source = $data['source'] ?? null;
        $kind = $data['kind'] ?? null;

        if (! is_string($source) || $source === '' || ! is_string($kind) || $kind === '') {
            throw new InvalidArgumentException('An error event needs a source and a kind.');
        }

        $platform = self::text($data['platform'] ?? null);

        return new self(
            source: $source,
            kind: $kind,
            class: self::text($data['class'] ?? null) ?? '',
            message: self::text($data['message'] ?? null) ?? '',
            trace: self::text($data['trace'] ?? null) ?? '',
            status: self::number($data['status'] ?? null),
            method: self::text($data['method'] ?? null),
            route: self::text($data['route'] ?? null),
            version: self::text($data['version'] ?? null),
            platform: $platform === null || $platform === '' ? 'server' : $platform,
            device: self::text($data['device'] ?? null),
            userId: self::text($data['user_id'] ?? null),
            at: self::text($data['at'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function number(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) ? (int) $value : null;
    }
}
