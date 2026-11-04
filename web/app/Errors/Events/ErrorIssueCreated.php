<?php

declare(strict_types=1);

namespace App\Errors\Events;

use App\Models\ErrorIssue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A kind of failure the error center had not seen before has just become an issue. Fired once per issue, by
 * ErrorRecorder only, after its transaction has committed: a listener (the instant e-mail) never runs inside it, can never
 * roll it back, and an exception it throws is swallowed by the recorder. A resolved issue that comes back is not new and
 * fires nothing. A queued listener gets the issue by its key (SerializesModels), read again when it runs.
 */
final class ErrorIssueCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly ErrorIssue $issue) {}
}
