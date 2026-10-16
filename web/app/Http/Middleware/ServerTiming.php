<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

/**
 * Says on every answer how long the server took and how much of it was the database (Server-Timing, shown by browsers
 * in their network panel), so the site's speed can be measured where it runs, apart from the network in between.
 */
final class ServerTiming
{
    private float $database = 0.0;

    private int $queries = 0;

    public function handle(Request $request, Closure $next): Response
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            $this->database += $query->time;
            $this->queries++;
        });

        $response = $next($request);

        $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f;desc="%d queries"',
            (microtime(true) - $started) * 1000,
            $this->database,
            $this->queries,
        ));

        return $response;
    }
}
