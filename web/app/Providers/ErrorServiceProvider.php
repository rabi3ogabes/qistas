<?php

declare(strict_types=1);

namespace App\Providers;

use App\Errors\ClientSender;
use App\Errors\ErrorAlerts;
use App\Errors\ErrorCapture;
use App\Errors\Events\ErrorIssueCreated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The error center's server capture (design sections 3 and 8): one ErrorCapture per request (or per job in a queue
 * worker), the slow-query listener, what queue workers and scheduled tasks need (captureWork), and the last call that
 * records what a command left behind. The exception hook and the error-page middleware are wired in bootstrap/app.php.
 * Also the request limits of the two reporters (section 9), and the listener that sends the instant e-mail (section 8).
 */
final class ErrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ErrorCapture::class);
    }

    public function boot(): void
    {
        $this->limitReporters();

        // The instant e-mail for a brand-new server error (design section 8). The recorder fires this once per new issue, after
        // the issue is stored; ErrorAlerts decides whether it alerts and swallows whatever goes wrong. Registered here and
        // nowhere else (no class in app/Listeners, which Laravel would discover on its own as a second listener).
        Event::listen(ErrorIssueCreated::class, [ErrorAlerts::class, 'handle']);

        // Every query passes here. A query under a millisecond costs one comparison; any other one, a read of the setting
        // from the configuration object taken here once (no container lookup, and a change made at run time still
        // counts) and a second comparison. Only a slow query reaches the capture layer. An empty, mistyped or
        // sub-millisecond setting is 3000 (ruling R9).
        $config = $this->app->make('config');

        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use ($config): void {
            try {
                if ($query->time < 1.0 || $query->time < ErrorCapture::slowQueryMs($config->get('qistas.errors.slow_query_ms'))
                    || ErrorCapture::capturing()) {
                    return;
                }

                app(ErrorCapture::class)->slowQuery($query);
            } catch (Throwable) {
                // never break a query
            }
        });

        $this->captureWork();

        // A command that throws is reported after Laravel has run the work deferred for the end of the command, so what
        // its failure noted is recorded here, as the process ends. On a request this finds nothing left to do.
        $this->app->terminating(fn () => $this->flushCapture());
    }

    /**
     * Queue workers and scheduled tasks. A worker reports a job's failure only after the job's deferred work has run, and
     * before the next job it throws the job's scope away (ErrorCapture with it): so what was noted is recorded at its
     * Looping event, which comes before that, and when it stops. Inside a request (the /internal/cron request runs the
     * scheduler and a worker), what a job or a task reports is its own, not the request's.
     */
    private function captureWork(): void
    {
        Event::listen(WorkerStarting::class, static fn () => ErrorCapture::workStarts());
        Event::listen(Looping::class, function (): void {
            $this->flushCapture();
        });
        Event::listen(WorkerStopping::class, function (): void {
            $this->flushCapture();
            ErrorCapture::workEnds();
        });

        Event::listen(ScheduledTaskStarting::class, static fn () => ErrorCapture::workStarts());
        Event::listen(ScheduledTaskFinished::class, static fn () => ErrorCapture::workEnds());
        Event::listen(ScheduledTaskFailed::class, static fn (ScheduledTaskFailed $event) => ErrorCapture::workEnds($event->exception));
    }

    /** Records what the current ErrorCapture noted, if there is one; never throws. */
    private function flushCapture(): void
    {
        try {
            if ($this->app->resolved(ErrorCapture::class)) {
                $this->app->make(ErrorCapture::class)->flush();
            }
        } catch (Throwable) {
            // nothing to tell
        }
    }

    /**
     * How often the two reporters may be posted to, a minute at a time, and then 429: the website's scripts 20 from one
     * address, the phone app 30 from one app token made for the phone, or from one address when it has none (ClientSender).
     *
     * The website's refusal is a bare 429 with no page (a script does not read one, and the site's error page would only
     * cost a render). The phone's is the API's usual 429 with its error body (ApiErrors turns a limiter's own response into
     * a 500 on /api, so it is left to the framework there).
     */
    private function limitReporters(): void
    {
        RateLimiter::for('client-errors', static fn (Request $request) => Limit::perMinute(20)
            ->by('client-errors|'.$request->ip())
            ->response(static fn (Request $request, array $headers) => response('', 429, $headers)));

        RateLimiter::for('client-errors-api', static fn (Request $request) => Limit::perMinute(30)
            ->by('client-errors-api|'.ClientSender::key($request)));
    }
}
