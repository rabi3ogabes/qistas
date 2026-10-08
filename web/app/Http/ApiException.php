<?php

namespace App\Http;

use RuntimeException;

/**
 * A failure the API reports in its standard error shape. The message is for people (already translated); the
 * code is for programs and never changes.
 */
final class ApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra  more keys for the error object, e.g. ['fields' => [...]] */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
