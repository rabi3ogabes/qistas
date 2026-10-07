<?php

namespace App\Entitlements;

enum FeatureType: string
{
    /** On or off. */
    case Toggle = 'toggle';

    /** A cap on how many things exist at once (customers). Counted live. */
    case Limit = 'limit';

    /** A cap on how many times something may be done per calendar month (PDF statements). */
    case Quota = 'quota';
}
