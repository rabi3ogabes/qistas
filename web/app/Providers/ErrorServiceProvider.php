<?php

declare(strict_types=1);

namespace App\Providers;

use App\Errors\ErrorCapture;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The error center's server capture (design sections 3 and 8): one ErrorCapture per request (or per job in a queue
 * worker), the slow-query listener, and the last call that records what a command left behind. The exception hook and
 * the error-page middleware are wired in bootstrap/app.php.
 */
final class ErrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ErrorCapture::class);
    }

    public function boot(): void
    {
        // Every query passes here, so the common case is one comparison: no threshold is ever under a millisecond (an
        // empty or mistyped setting is 3000), and nothing is looked up for a query faster than that.
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if ($query->time < 1.0 || ErrorCapture::capturing()) {
                return;
            }

            app(ErrorCapture::class)->slowQuery($query);
        });

        // A command that throws is reported after Laravel has run the work deferred for the end of the command, so what
        // its failure noted is recorded here, as the process ends. On a request this finds nothing left to do.
        $this->app->terminating(function (): void {
            try {
                if ($this->app->resolved(ErrorCapture::class)) {
                    $this->app->make(ErrorCapture::class)->flush();
                }
            } catch (Throwable) {
                // the process is ending: nothing to tell
            }
        });
    }
}
