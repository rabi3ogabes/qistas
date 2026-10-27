<?php

namespace App\Billing\Card;

use RuntimeException;

/** A webhook message that Stripe did not sign with this site's secret, or signed too long ago. */
final class CardSignatureInvalid extends RuntimeException {}
