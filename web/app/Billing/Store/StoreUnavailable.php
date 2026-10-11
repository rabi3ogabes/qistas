<?php

namespace App\Billing\Store;

use RuntimeException;

/** The store could not be asked (not set up, unreachable or failing); nothing was decided. */
final class StoreUnavailable extends RuntimeException {}
