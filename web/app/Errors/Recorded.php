<?php

declare(strict_types=1);

namespace App\Errors;

/**
 * What ErrorRecorder::record() did with one event. A caller never has to act on it (recording can never break a request),
 * but the capture layer and the tests read it.
 */
enum Recorded: string
{
    /** Capture is switched off (QISTAS_ERRORS=false): nothing was written anywhere, not even the spool. */
    case Off = 'off';

    /** The issue is ignored: the sighting was dropped silently, not counted and not alerted. */
    case Ignored = 'ignored';

    /**
     * Not stored: a new issue over a flood cap (counted in error_noise_days as `dropped`), an event the spool had no room
     * for, or a call made while the recorder was already at work (a listener reacting to the recorder itself).
     */
    case Dropped = 'dropped';

    /** The database refused the write, so the scrubbed event waits in the spool for qistas:errors:import-spool. */
    case Spooled = 'spooled';

    /** A sighting of an issue already known: counted, sampled, its day and person added. */
    case Counted = 'counted';

    /** A kind of failure never seen before: a new issue, announced with ErrorIssueCreated. */
    case Created = 'created';

    /** A sighting of a resolved issue: it is open again, with the version it came back in. */
    case Reopened = 'reopened';
}
