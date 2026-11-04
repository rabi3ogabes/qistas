<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use PDOException;
use RuntimeException;

/**
 * A queued job that always fails, for the error center: with a failed insert whose values are an e-mail address and a
 * national ID (`query`), or with a plain RuntimeException (anything else). Its tries come from the worker's --tries.
 */
final class FailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $failure = 'runtime') {}

    public function handle(): void
    {
        if ($this->failure !== 'query') {
            throw new RuntimeException('The job broke');
        }

        $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "customers_email_unique"'
            ."\nDETAIL:  Key (email, national_id)=(sara.ali@example.com, 1098765432) already exists.");
        $pdo->errorInfo = ['23505', 7, 'duplicate key value'];

        throw new QueryException('pgsql', 'insert into "customers" ("email", "national_id") values (?, ?)', ['sara.ali@example.com', '1098765432'], $pdo);
    }
}
