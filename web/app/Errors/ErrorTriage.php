<?php

declare(strict_types=1);

namespace App\Errors;

use App\Models\ErrorIssue;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * What platform staff decide about an issue (design section 4): resolve it, ignore it, or reopen it. With ErrorRecorder, the
 * only code that changes an issue's status; nothing else can, since the status is not mass assignable.
 *
 * A change records who made it and when (`resolved_by`/`resolved_at`, `ignored_by`/`ignored_at`; reopening clears both, and
 * moving from one decision to the other drops the earlier one, so the issue only ever says what was decided last) and writes
 * one audit row, `error.resolved`, `error.ignored` or `error.reopened`, with the issue's id and the status it came from.
 * Asking for the status the issue already has changes nothing and writes nothing.
 *
 * The counters belong to the recorder: this writes only the status and the two decisions, so a sighting counted a moment
 * before (or after) is never written over, and reopening does not touch `occurrences`. A staff reopen is not the issue
 * "coming back": `reopened_at` and `reopened_version` stay as the recorder left them.
 */
final class ErrorTriage
{
    public function resolve(ErrorIssue $issue, User $by): ErrorIssue
    {
        return $this->move($issue, ErrorIssue::STATUS_RESOLVED, $by, 'error.resolved');
    }

    public function ignore(ErrorIssue $issue, User $by): ErrorIssue
    {
        return $this->move($issue, ErrorIssue::STATUS_IGNORED, $by, 'error.ignored');
    }

    public function reopen(ErrorIssue $issue, User $by): ErrorIssue
    {
        return $this->move($issue, ErrorIssue::STATUS_OPEN, $by, 'error.reopened');
    }

    private function move(ErrorIssue $issue, string $to, User $by, string $action): ErrorIssue
    {
        return DB::transaction(function () use ($issue, $to, $by, $action): ErrorIssue {
            // The row is read again under a lock, so two staff pressing at once cannot both "come from" open, and the
            // status shown on a page that has been open for an hour is not what is trusted.
            $current = ErrorIssue::query()->whereKey($issue->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if ($from === $to) {
                return $current;
            }

            $now = now();
            $current->forceFill([
                'status' => $to,
                'resolved_at' => $to === ErrorIssue::STATUS_RESOLVED ? $now : null,
                'resolved_by' => $to === ErrorIssue::STATUS_RESOLVED ? $by->id : null,
                'ignored_at' => $to === ErrorIssue::STATUS_IGNORED ? $now : null,
                'ignored_by' => $to === ErrorIssue::STATUS_IGNORED ? $by->id : null,
            ])->save();

            Audit::record($action, $current, ['issue' => $current->id, 'from' => $from], null, $by->id);

            return $current;
        });
    }
}
