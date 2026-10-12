<?php

use App\Models\CronRun;

/*
| A public status page (Sprint 2, decision D10): whether Qistas answers right now and when scheduled work (reminders,
| late fees, backups) last ran. It says how things are in plain words and never shows anything private.
*/

beforeEach(fn () => $this->travelTo('2026-10-12 10:00:00'));

function cronRun(int $minutesAgo, string $outcome = 'ok', ?string $error = null): void
{
    CronRun::create(['started_at' => now()->subMinutes($minutesAgo), 'finished_at' => now()->subMinutes($minutesAgo), 'outcome' => $outcome, 'jobs' => 2, 'error' => $error]);
}

it('says everything is running, with when scheduled work last ran', function () {
    config(['qistas.cron.secret' => 'cron-secret-value']);
    cronRun(3);

    $this->get('/status')->assertOk()
        ->assertSee('All systems running')
        ->assertSee('Website and app')->assertSee('Database')->assertSee('Scheduled work')
        ->assertSee('Last ran 3 minutes ago')
        ->assertDontSee('Some work is delayed');
});

it('says scheduled work is delayed when it failed or went quiet, and never what went wrong', function () {
    config(['qistas.cron.secret' => 'cron-secret-value']);
    cronRun(2, 'failed', 'SQLSTATE[08006] could not reach db.internal');

    $this->get('/status')->assertOk()->assertSee('Some work is delayed')->assertSee('Delayed')
        ->assertDontSee('db.internal')->assertDontSee('SQLSTATE');

    CronRun::query()->delete();
    cronRun(60);

    $this->get('/status')->assertSee('Some work is delayed')->assertSee('Last ran 1 hour ago');
});

it('says scheduled work has not started before its first run, or while it has no secret to run with', function () {
    config(['qistas.cron.secret' => 'cron-secret-value']);
    $this->get('/status')->assertOk()->assertSee('Not started yet');

    // Without the secret the cron endpoint is closed: an old run says nothing about now.
    config(['qistas.cron.secret' => '']);
    cronRun(3);
    $this->get('/status')->assertOk()->assertSee('Not started yet')->assertDontSee('Last ran')->assertDontSee('CRON_SECRET');
});

it('shows nothing private', function () {
    config(['qistas.cron.secret' => 'cron-secret-value']);
    cronRun(1);
    [$owner] = owner();

    $this->get('/status')->assertOk()
        ->assertDontSee('cron-secret-value')
        ->assertDontSee($owner->email)
        ->assertDontSee((string) config('database.default'))
        ->assertDontSee('jobs');
});

it('answers in each language', function () {
    $this->get('/ar/status')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('حالة الخدمة');
});
