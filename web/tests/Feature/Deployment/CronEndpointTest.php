<?php

use App\Models\CronRun;
use App\Reports\PlatformOverview;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\BoomJob;
use Tests\Support\ProbeJob;
use Tests\Support\SlowJob;

/*
| The cron endpoint is how scheduled work runs on a host with no scheduler and no queue worker: something outside
| (a GitHub workflow, Vercel Cron, any pinger) calls it every few minutes with a secret, and each call does one
| bounded slice of work. It is a door into the application, so most of these tests are about who may not use it.
*/

const CRON_SECRET = 'cron-secret-for-tests-0123456789abcdef';

beforeEach(function () {
    config(['qistas.cron.secret' => CRON_SECRET, 'qistas.cron.max_seconds' => 50]);
    ProbeJob::reset();
    SlowJob::reset();
});

/** Calls the endpoint with [$secret] as the bearer token (none when null). */
function tick(string $method = 'get', ?string $secret = CRON_SECRET, string $uri = '/internal/cron')
{
    return test()->withHeaders($secret === null ? [] : ['Authorization' => 'Bearer '.$secret])->{$method}($uri);
}

function overviewCron(): array
{
    return collect(app(PlatformOverview::class)->health())->firstWhere('key', 'cron');
}

describe('who may call it', function () {
    it('says it is not set up, and does nothing, while no secret exists', function (?string $unset) {
        config(['qistas.cron.secret' => $unset]);
        $tenant = workspaceOn('pro');
        ProbeJob::dispatch($tenant->id)->onConnection('database');

        tick(secret: 'anything')->assertStatus(503)->assertExactJson(['error' => 'not_configured']);
        tick(secret: '')->assertStatus(503);

        expect(ProbeJob::$ran)->toBe(0)->and(CronRun::count())->toBe(0);
    })->with([[null], ['']]);

    it('refuses a call with no secret, a wrong one of the same length, and wrong ones of other lengths', function (?string $given) {
        tick(secret: $given)->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        expect(CronRun::count())->toBe(0);
    })->with([
        'none' => [null],
        'empty' => [''],
        'same length, last character different' => [substr(CRON_SECRET, 0, -1).'!'],
        'same length, first character different' => ['!'.substr(CRON_SECRET, 1)],
        'a prefix of the secret' => [substr(CRON_SECRET, 0, 10)],
        'the secret with something added' => [CRON_SECRET.'x'],
        'the secret with a space' => [CRON_SECRET.' '],
    ]);

    it('does not take the secret from the address or the body, only from the Authorization header', function () {
        test()->get('/internal/cron?secret='.CRON_SECRET)->assertStatus(401);
        test()->post('/internal/cron', ['secret' => CRON_SECRET])->assertStatus(401);
        test()->withHeaders(['Authorization' => 'Basic '.base64_encode(CRON_SECRET)])->get('/internal/cron')->assertStatus(401);
    });

    it('accepts the right secret on GET (what Vercel Cron sends) and on POST', function (string $method) {
        tick($method)->assertOk()->assertJsonPath('data.outcome', 'ok');
    })->with(['get', 'post']);

    it('is a plain endpoint: no session, no cookies, no CSRF token to fetch first', function () {
        $route = Route::getRoutes()->getByName('internal.cron');

        expect($route)->not->toBeNull()->and($route->gatherMiddleware())->not->toContain('web');

        tick('post')->assertOk()->assertCookieMissing(config('session.cookie'));
    });

    it('is rate-limited, so the secret cannot be guessed at speed', function () {
        foreach (range(1, 30) as $_) {
            tick(secret: 'wrong')->assertStatus(401);
        }

        tick(secret: 'wrong')->assertStatus(429);
        tick()->assertStatus(429);
    });

    it('answers HEAD and OPTIONS and other verbs with nothing that runs the work', function (string $method) {
        test()->withHeaders(['Authorization' => 'Bearer '.CRON_SECRET])->call($method, '/internal/cron')->assertStatus(405);

        expect(CronRun::count())->toBe(0);
    })->with(['PUT', 'PATCH', 'DELETE']);
});

describe('what a call does', function () {
    it('runs the scheduler', function () {
        $ran = 0;
        app(Schedule::class)->call(function () use (&$ran) {
            $ran++;
        })->everyMinute();

        tick()->assertOk();

        expect($ran)->toBe(1);
    });

    it('registers the demo clean-up with the scheduler', function () {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        expect($commands->contains(fn ($command) => str_contains($command, 'qistas:prune-demo')))->toBeTrue();
    });

    it('works through work waiting on the database queue, in the right workspace', function () {
        $tenant = workspaceOn('pro');
        ProbeJob::dispatch($tenant->id)->onConnection('database');
        expect(DB::table('jobs')->count())->toBe(1);

        tick()->assertOk()->assertJsonPath('data.jobs', 1);

        expect(ProbeJob::$ran)->toBe(1)->and(ProbeJob::$tenantSeen)->toBe($tenant->id)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('records each run: when it started and ended, how it went, and how many jobs it did', function () {
        $tenant = workspaceOn('pro');
        ProbeJob::dispatch($tenant->id)->onConnection('database');
        ProbeJob::dispatch($tenant->id)->onConnection('database');

        tick()->assertOk();

        $run = CronRun::sole();
        expect($run->outcome)->toBe('ok')->and($run->jobs)->toBe(2)->and($run->started_at)->not->toBeNull()
            ->and($run->finished_at)->not->toBeNull()->and($run->finished_at->gte($run->started_at))->toBeTrue();
    });

    it('is not stopped by one job that fails, and does the jobs behind it', function () {
        $tenant = workspaceOn('pro');
        BoomJob::dispatch()->onConnection('database');
        ProbeJob::dispatch($tenant->id)->onConnection('database');

        tick()->assertOk();

        expect(ProbeJob::$ran)->toBe(1)->and(CronRun::sole()->outcome)->toBe('ok')->and(DB::table('failed_jobs')->count())->toBe(1);
    });

    it('records a slice that blew up as failed, says what went wrong, answers 500, and lets go of its lock', function () {
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andThrow(new RuntimeException('the scheduler exploded'));

        tick()->assertStatus(500)->assertJsonPath('data.outcome', 'failed')->assertJsonPath('error', 'run_failed');

        $run = CronRun::sole();
        expect($run->outcome)->toBe('failed')->and($run->error)->toContain('the scheduler exploded')->and($run->finished_at)->not->toBeNull()
            ->and(Cache::lock('cron:run', 60)->get())->toBeTrue();
    });

    it('stops at its time cap and leaves the rest for the next call', function () {
        config(['qistas.cron.max_seconds' => 1]);
        foreach (range(1, 3) as $_) {
            SlowJob::dispatch()->onConnection('database');
        }

        tick()->assertOk();

        expect(SlowJob::$ran)->toBeLessThan(3)->and(SlowJob::$ran)->toBeGreaterThan(0)
            ->and(DB::table('jobs')->count())->toBe(3 - SlowJob::$ran);

        tick()->assertOk();

        expect(SlowJob::$ran)->toBeGreaterThan(1);
    });

    it('does nothing and says so when another call is already running', function () {
        $tenant = workspaceOn('pro');
        ProbeJob::dispatch($tenant->id)->onConnection('database');
        $held = Cache::lock('cron:run', 60);
        expect($held->get())->toBeTrue();

        tick()->assertStatus(409)->assertExactJson(['error' => 'busy']);

        expect(ProbeJob::$ran)->toBe(0)->and(CronRun::count())->toBe(0);

        $held->release();
        tick()->assertOk();

        expect(ProbeJob::$ran)->toBe(1);
    });

    it('lets go of its lock when it is done, so the next call is not turned away', function () {
        tick()->assertOk();
        tick()->assertOk();

        expect(CronRun::count())->toBe(2);
    });
});

describe('qistas:cron-status', function () {
    it('says plainly when nothing has run yet', function () {
        $this->artisan('qistas:cron-status')->expectsOutputToContain('No run yet')->assertSuccessful();
    });

    it('shows the last run and how long ago it was', function () {
        CronRun::create(['started_at' => now()->subMinutes(7), 'finished_at' => now()->subMinutes(7), 'outcome' => 'ok', 'jobs' => 3]);

        $this->artisan('qistas:cron-status')->expectsOutputToContain('7 minutes ago')->assertSuccessful();
    });

    it('lists the last failures with what went wrong', function () {
        CronRun::create(['started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'outcome' => 'failed', 'jobs' => 0, 'error' => 'The queue table is missing']);

        $this->artisan('qistas:cron-status')->expectsOutputToContain('The queue table is missing')->assertSuccessful();
    });
});

describe('the admin overview', function () {
    it('is calm before anything is set up', function () {
        config(['qistas.cron.secret' => null]);

        expect(overviewCron())->toMatchArray(['status' => 'off'])->and(overviewCron()['detail'])->toContain('Not set up yet');
    });

    it('waits for the first run once a secret exists', function () {
        expect(overviewCron())->toMatchArray(['status' => 'off'])->and(overviewCron()['detail'])->toContain('first run');
    });

    it('is good while the last run is recent', function () {
        CronRun::create(['started_at' => now()->subMinutes(4), 'finished_at' => now()->subMinutes(4), 'outcome' => 'ok', 'jobs' => 0]);

        expect(overviewCron()['status'])->toBe('ok');
    });

    it('asks for attention when a set-up scheduler has gone quiet', function () {
        CronRun::create(['started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(20), 'outcome' => 'ok', 'jobs' => 0]);

        expect(overviewCron()['status'])->toBe('warn');
    });

    it('asks for attention when the last run failed, even a recent one', function () {
        CronRun::create(['started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinutes(2), 'outcome' => 'failed', 'jobs' => 0, 'error' => 'x']);

        expect(overviewCron())->toMatchArray(['status' => 'warn'])->and(overviewCron()['detail'])->toContain('failed');
    });
});
