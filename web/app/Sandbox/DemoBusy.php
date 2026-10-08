<?php

namespace App\Sandbox;

use RuntimeException;

/** There are as many demo accounts as the site allows at once; try again when some have expired. */
final class DemoBusy extends RuntimeException {}
