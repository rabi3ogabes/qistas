<?php

declare(strict_types=1);

namespace App\Providers;

use App\Errors\ErrorCapture;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The error center's server capture (design sections 3 and 8): one ErrorCapture per request (or per job in a queue
 * worker), the slow-query listener, and the last call that records what a command left behind. The exception hook and
 * the error-page middleware are wired in bootstrap/app.php. Also the request limits of the two reporters (section 9).
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

    /**
     * How often the two reporters may be posted to, a minute at a time, and then 429: the website's scripts 20 from one
     * address, the phone app 30 from one app token, or from one address when it has none.
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
            ->by('client-errors-api|'.self::sender($request)));
    }

    /**
     * Who a phone's report is counted against: its app token; a made-up, expired or revoked token is nobody, so a stream
     * of them is counted against the address like any other stranger. A token store that fails is nobody either.
     */
    private static function sender(Request $request): string
    {
        try {
            $user = $request->user('sanctum');

            if ($user instanceof User) {
                // The token's own number: one person with two phones has two. (A token that has none, such as the one
                // Sanctum::actingAs makes for a test, throws here and is counted against the address below.)
                return 'token:'.$user->currentAccessToken()->getKey();
            }
        } catch (Throwable) {
            // counted against the address below
        }

        return 'address:'.$request->ip();
    }
}
