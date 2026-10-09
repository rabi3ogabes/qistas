<?php

declare(strict_types=1);

namespace App\Theme;

use RuntimeException;

/** A look that cannot be published because some text would still be unreadable after the automatic repair. */
final class AppearanceRefused extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $failing  the contrast pairs that still fail (mode, label, ratio, minimum) */
    public function __construct(public readonly array $failing)
    {
        parent::__construct('This look cannot be published: '.count($failing).' pair(s) of colours would be unreadable.');
    }
}
