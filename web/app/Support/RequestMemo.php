<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;

/**
 * Answers a request asks for many times (what the plan allows, a person's role) read from the database once and
 * remembered for the rest of the request. Never out of date: anything that writes to a table an answer was read from
 * forgets every answer about it (AppServiceProvider sends it every write), and an answer is read again once the clock
 * has moved a minute from when it was read, so a plan running out is seen. Bound per request (scoped).
 */
final class RequestMemo
{
    /** Each kind of answer, by the tables it is read from. */
    public const TABLES = [
        'entitlements' => ['subscriptions', 'plans', 'plan_features', 'platform_features', 'tenant_overrides'],
        'roles' => ['tenant_users'],
        'photos' => ['user_photos'],
    ];

    /** An answer is read again once the clock has moved this far from when it was read (a plan may have run out). */
    private const KEEP_SECONDS = 60;

    /** @var array<string, array<string, array{at: int, value: mixed}>> kind => key => answer and when it was read */
    private array $answers = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function remember(string $kind, string $key, Closure $read): mixed
    {
        $now = now()->getTimestamp();
        $kept = $this->answers[$kind][$key] ?? null;
        // A clock set by hand (a test moving time) is followed to the second; a real one keeps an answer a minute.
        $stale = $kept === null || (Carbon::hasTestNow() ? $kept['at'] !== $now : abs($now - $kept['at']) >= self::KEEP_SECONDS);

        if ($stale) {
            $kept = $this->answers[$kind][$key] = ['at' => $now, 'value' => $read()];
        }

        return $kept['value'];
    }

    /** Forgets everything: a new request starts with nothing remembered. */
    public function forgetAll(): void
    {
        $this->answers = [];
    }

    /** Forgets what a write to a table could have changed. */
    public function written(QueryExecuted $query): void
    {
        if ($this->answers === [] || preg_match('/^\s*(insert|update|delete|upsert)\b/i', $query->sql) !== 1) {
            return;
        }

        foreach (self::TABLES as $kind => $tables) {
            foreach ($tables as $table) {
                if (preg_match('/\b(into|update|from)\s+"?'.$table.'"?[\s(]/i', $query->sql.' ') === 1) {
                    unset($this->answers[$kind]);

                    continue 2;
                }
            }
        }
    }
}
