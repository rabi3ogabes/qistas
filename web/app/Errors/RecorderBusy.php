<?php

declare(strict_types=1);

namespace App\Errors;

use RuntimeException;

/**
 * ErrorRecorder::replay() was asked to record while the recorder was already at work (from inside one of its own
 * listeners). Nothing was written: the spool keeps the line for the next import, and no try is counted against it.
 */
final class RecorderBusy extends RuntimeException {}
