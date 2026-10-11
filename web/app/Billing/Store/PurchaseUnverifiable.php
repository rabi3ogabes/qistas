<?php

namespace App\Billing\Store;

use RuntimeException;

/** The store does not recognise the purchase token, or the token is not for this app. */
final class PurchaseUnverifiable extends RuntimeException {}
