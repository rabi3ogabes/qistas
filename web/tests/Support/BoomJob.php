<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

/** A job that always fails, to prove one bad job does not stop a cron tick or the jobs behind it. */
final class BoomJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
