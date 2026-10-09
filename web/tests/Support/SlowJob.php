<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** A job that takes a while, to prove the cron worker stops at its time cap and leaves the rest for the next tick. */
final class SlowJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static int $ran = 0;

    public function __construct(private readonly int $milliseconds = 1100) {}

    public function handle(): void
    {
        usleep($this->milliseconds * 1000);
        self::$ran++;
    }

    public static function reset(): void
    {
        self::$ran = 0;
    }
}
