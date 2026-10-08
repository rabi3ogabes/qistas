<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

/** How many rows a client may ask for on one page: what it says, between 1 and 100, otherwise 20. */
final class PerPage
{
    public const DEFAULT = 20;

    public const MAX = 100;

    public static function of(Request $request): int
    {
        $asked = (int) $request->query('per_page', self::DEFAULT);

        return $asked < 1 ? self::DEFAULT : min($asked, self::MAX);
    }
}
