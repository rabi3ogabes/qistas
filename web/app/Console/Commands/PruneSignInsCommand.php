<?php

namespace App\Console\Commands;

use App\Models\SignInEvent;
use Illuminate\Console\Command;

/**
 * Removes sign-in records older than qistas.sign_in_days (90 by default), read when it runs so a change of setting
 * needs no restart. This is the only thing that removes them: the model refuses to be deleted, and this command does not
 * ask it to.
 */
final class PruneSignInsCommand extends Command
{
    protected $signature = 'qistas:sign-ins:prune';

    protected $description = 'Delete sign-in records older than the retention period (qistas.sign_in_days)';

    public function handle(): int
    {
        $setting = config('qistas.sign_in_days');
        $days = is_numeric($setting) ? (int) $setting : 0;

        // A zero, a negative number or text would make the cut-off now, and the whole history would go. Better to say so.
        if ($days < 1) {
            $this->components->error('qistas.sign_in_days (QISTAS_SIGN_IN_DAYS) must be a whole number of days, at least 1. Nothing was removed.');

            return self::FAILURE;
        }

        // A query-builder delete on purpose: it does not go through the model, so the model's "append-only" refusal
        // (which guards every other path) is not asked, and the whole batch is one statement.
        $removed = SignInEvent::query()->toBase()->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("Removed {$removed} sign-in ".($removed === 1 ? 'record' : 'records')." older than {$days} days.");

        return self::SUCCESS;
    }
}
