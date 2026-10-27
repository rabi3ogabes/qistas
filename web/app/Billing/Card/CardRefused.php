<?php

namespace App\Billing\Card;

use RuntimeException;

/**
 * A refusal whose message is meant for the person: a card Stripe declined, or one of Qistas' own checks ("this plan
 * is not for sale", "Pro is already on"). Show getMessage() as it is.
 */
final class CardRefused extends RuntimeException {}
