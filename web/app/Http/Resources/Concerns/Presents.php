<?php

namespace App\Http\Resources\Concerns;

use App\Support\Money;
use Carbon\CarbonInterface;

/** How the API writes amounts and moments: exact decimal strings, UTC times in ISO 8601, plain dates. */
trait Presents
{
    /** An amount as a string with two decimals ("1200.00"); never a number, so nothing is lost on the way. */
    protected function money(?string $amount): ?string
    {
        return $amount === null ? null : Money::add($amount, '0', 2);
    }

    protected function moment(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    protected function day(?CarbonInterface $day): ?string
    {
        return $day?->format('Y-m-d');
    }
}
