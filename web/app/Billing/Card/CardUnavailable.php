<?php

namespace App\Billing\Card;

use RuntimeException;

/** Stripe could not be asked (network, a fault on Stripe's side, keys it refuses): nothing changed; try again. */
final class CardUnavailable extends RuntimeException {}
