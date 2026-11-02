<?php

namespace App\Support;

use App\Billing\Card\CardSettings;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\LiveStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Entitlements\Feature;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Mail\MailSettings;
use App\Models\CronRun;
use App\Models\User;
use App\Sandbox\DemoCleanup;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * What the owner must see before real customers come (`qistas:go-live-check`, go-live basics 6.3): sixteen checks, each
 * pass, warn or fail with one plain line saying what to do next.
 *
 * It changes no data. The one thing it does that looks like a write is asking the application itself for the terms and
 * privacy pages (through the HTTP kernel, so no network), done with the session kept in memory; rendering them can warm a
 * cache entry, and nothing else is stored.
 *
 * It never shows a secret, a customer's address or the value of a setting: a setting is named, not quoted, and a check
 * that cannot run says so without saying why (the reason may carry a connection string).
 *
 * @phpstan-import-type Plan from DemoCleanup
 *
 * @phpstan-type Status 'pass'|'warn'|'fail'
 * @phpstan-type Outcome array{0: Status, 1: string}
 * @phpstan-type Result array{key: string, status: Status, title: string, detail: string}
 */
final class GoLiveCheck
{
    /** The scheduler has to have run within this many minutes. */
    private const SCHEDULER_MINUTES = 15;

    /** @var Plan|null */
    private ?array $plan = null;

    /** @return list<Result> */
    public static function run(): array
    {
        return (new self)->results();
    }

    /** @return list<Result> */
    private function results(): array
    {
        $checks = [
            'env' => ['Production mode', $this->env(...)],
            'debug' => ['Debug mode off', $this->debug(...)],
            'url' => ['HTTPS address', $this->url(...)],
            'demo' => ['Built-in demo mode off', $this->demo(...)],
            'demo-data' => ['No old demo accounts', $this->demoData(...)],
            'demo-domain' => ['No look-alike demo addresses', $this->demoDomain(...)],
            'test-workspaces' => ['Staff test workspaces', $this->testWorkspaces(...)],
            'mail' => ['E-mail can be sent', $this->mail(...)],
            'cron' => ['Scheduler is running', $this->cron(...)],
            'geo' => ['Visitor country header', $this->geo(...)],
            'billing' => ['Card billing gateway', $this->billing(...)],
            'admin' => ['A super admin exists', $this->admin(...)],
            'support' => ['Support e-mail', $this->support(...)],
            'legal' => ['Terms and privacy pages', $this->legal(...)],
            'database' => ['PostgreSQL database', $this->database(...)],
            'storage' => ['Storage is writable', $this->storage(...)],
        ];

        $results = [];

        foreach ($checks as $key => [$title, $check]) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                // The output says which kind of fault and never its message (it can carry a connection string); the
                // whole story goes to the application's log through the exception handler.
                report($e);
                [$status, $detail] = ['fail', 'This check could not run ('.class_basename($e).'). Fix what it could not reach, then run it again.'];
            }

            $results[] = ['key' => $key, 'status' => $status, 'title' => $title, 'detail' => $detail];
        }

        return $results;
    }

    /** @return Outcome */
    private function env(): array
    {
        return config('app.env') === 'production'
            ? ['pass', 'APP_ENV is production.']
            : ['fail', 'APP_ENV is not production. Set APP_ENV=production on the server.'];
    }

    /** @return Outcome */
    private function debug(): array
    {
        return config('app.debug') ? ['fail', 'APP_DEBUG is on and would show internal errors to visitors. Set APP_DEBUG=false.'] : ['pass', 'APP_DEBUG is off.'];
    }

    /** @return Outcome */
    private function url(): array
    {
        if (! str_starts_with(strtolower(trim((string) config('app.url'))), 'https://')) {
            return ['fail', 'APP_URL does not start with https://. Set it to the https address of the site.'];
        }

        // As the framework reads the setting: true, 1, on and yes all mean secure.
        if (filter_var(config('session.secure'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== true) {
            return ['warn', 'The session cookie is not marked secure. Set SESSION_SECURE_COOKIE=true.'];
        }

        return ['pass', 'APP_URL is https and the session cookie is secure.'];
    }

    /** @return Outcome */
    private function demo(): array
    {
        return config('qistas.demo')
            ? ['fail', 'The built-in demo mode is on. Set QISTAS_DEMO=false.']
            : ['pass', 'The built-in demo mode is off.'];
    }

    /** @return Outcome */
    private function demoData(): array
    {
        $plan = $this->plan();
        $removable = count($plan['accounts']);
        $held = count($plan['held_back']);

        if ($removable + $held === 0) {
            return ['pass', 'No old demo account is left.'];
        }

        $detail = "{$removable} can be removed, {$held} ".($held === 1 ? 'is' : 'are').' held back (the list says why).';

        return ['fail', $detail.($removable > 0
            ? ' Run php artisan qistas:purge-demo, read the list, then add --confirm.'
            : ' Run php artisan qistas:purge-demo to read why.')];
    }

    /** @return Outcome */
    private function demoDomain(): array
    {
        // Counted by the cleanup's own rule, so "not an old demo account" can never drift from what a purge would touch.
        $count = 0;

        foreach (User::query()->whereRaw('lower(email) like ?', ['%@'.DemoCleanup::DOMAIN])->cursor() as $user) {
            $count += DemoCleanup::isDemoAccount($user) ? 0 : 1;
        }

        return $count === 0
            ? ['pass', 'No account outside the old demo uses the demo domain.']
            : ['warn', $this->count($count, 'account').' at the demo domain ('.DemoCleanup::DOMAIN.') '.($count === 1 ? 'is' : 'are').' not from the old demo and never purged. Check '.($count === 1 ? 'it is a real person' : 'they are real people').'.'];
    }

    /** @return Outcome */
    private function testWorkspaces(): array
    {
        $count = count($this->plan()['test_workspaces']);

        return $count === 0
            ? ['pass', 'No staff test workspace exists.']
            : ['warn', "Staff test workspaces exist: {$count}. Remove them when done: php artisan qistas:purge-demo --include-test."];
    }

    /**
     * Whether e-mail leaves the building, read from where the application itself reads it: a real mailer in the server's
     * environment wins; otherwise the SMTP server saved in the admin E-mail page (the database); otherwise the
     * configured default, which is the log unless MAIL_MAILER says otherwise.
     *
     * The mailer has to be one the application defines (a key of `mail.mailers`): a misspelt MAIL_MAILER passes every
     * other test and then fails on the first e-mail. What was typed is never printed, only a name that is defined (a
     * value like that can be a connection string with a password in it).
     *
     * @return Outcome
     */
    private function mail(): array
    {
        $settings = app(MailSettings::class);
        $saved = $settings->serverDecides() ? null : $settings->get();

        if ($saved !== null) {
            $mailer = 'smtp';
            $from = $saved['from_address'];
            $where = 'the SMTP server saved in the admin E-mail page';
            $fix = 'Fill in the From address in the admin E-mail page.';
        } else {
            $chosen = config('mail.default');
            $mailer = is_string($chosen) ? $chosen : '';
            $from = (string) config('mail.from.address');
            $where = 'the server environment';
            $fix = 'Set MAIL_FROM_ADDRESS.';
        }

        if (in_array($mailer, ['', 'log', 'array', 'null'], true)) {
            return ['fail', 'E-mail only goes to the log. Save the SMTP server in the admin E-mail page, or set MAIL_MAILER.'];
        }

        if (! array_key_exists($mailer, (array) config('mail.mailers'))) {
            return ['fail', 'MAIL_MAILER names a mailer this app does not have, so every e-mail would fail. Check its spelling (smtp is the usual one).'];
        }

        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL) === false) {
            return ['fail', "The from address is missing or not valid. {$fix}"];
        }

        return ['pass', "E-mail goes out through {$mailer} (settings from {$where}). Prove it with \"send a test\" in the admin E-mail page."];
    }

    /** @return Outcome */
    private function cron(): array
    {
        $last = CronRun::query()->orderByDesc('started_at')->first();

        if ($last === null) {
            return ['warn', 'No scheduled run recorded yet. Set CRON_SECRET and have a pinger call /internal/cron every 5 minutes.'];
        }

        $ago = $last->started_at->clone()->locale('en')->diffForHumans();

        if ($last->started_at->lt(now()->subMinutes(self::SCHEDULER_MINUTES))) {
            return ['fail', 'Last ran '.$ago.' (limit '.self::SCHEDULER_MINUTES.' minutes). Check the pinger and CRON_SECRET; php artisan qistas:cron-status.'];
        }

        if ($last->outcome === 'failed') {
            return ['warn', 'The last scheduled run failed. Run php artisan qistas:cron-status to see why.'];
        }

        return ['pass', 'Last ran '.$ago.'.'];
    }

    /** @return Outcome */
    private function geo(): array
    {
        return trim((string) config('qistas.geo_header')) !== ''
            ? ['pass', 'QISTAS_GEO_HEADER is set.']
            : ['warn', "QISTAS_GEO_HEADER is not set, so sign-up guesses the visitor's country. Set it to your CDN's country header."];
    }

    /**
     * Pro by card needs real Stripe keys behind it. The gateway is the one the application would use right now:
     * Stripe once a secret key is saved or set, a gateway that sends nothing anywhere until then, and the fake one only
     * where something has bound it by hand.
     *
     * @return Outcome
     */
    private function billing(): array
    {
        if (PlatformFeatures::state(Feature::CardBilling) === PlatformState::Off) {
            return ['pass', 'Card billing is switched off, so no card gateway is needed.'];
        }

        return $this->cardGateway(app(StripeGateway::class), app(CardSettings::class));
    }

    /**
     * Pro by card is on: is there a real gateway, and is it taking real money?
     *
     * @return Outcome
     */
    private function cardGateway(StripeGateway $gateway, CardSettings $settings): array
    {
        $fix = 'Save the Stripe keys in the admin Billing page, or switch it off.';

        if ($gateway instanceof FakeStripeGateway) {
            return ['warn', "Card billing is on but uses the fake gateway. {$fix}"];
        }

        if (! $gateway instanceof LiveStripeGateway) {
            return ['warn', "Card billing is on but no Stripe keys are saved. {$fix}"];
        }

        $missing = $this->missingCardKeys($settings);

        if ($missing !== []) {
            return ['warn', 'Card billing is on but Stripe is not fully connected. Missing: '.implode(', ', $missing).'. Connect it in the admin Billing page.'];
        }

        if ($settings->mode() === 'test') {
            return ['warn', 'Card billing is on with Stripe test keys, so no real card is charged. Save the live keys when ready.'];
        }

        return ['pass', 'Card billing is on and uses the live Stripe gateway.'];
    }

    /**
     * What a Stripe connection still lacks beyond the secret key, by name only (never a key itself).
     *
     * @return list<string>
     */
    private function missingCardKeys(CardSettings $settings): array
    {
        if ($settings->ready()) {
            return [];
        }

        $missing = [];
        $mode = $settings->mode();
        $publishable = $settings->publishableKey();

        if ($mode === null) {
            $missing[] = 'a valid secret key';
        }

        if ($publishable === null) {
            $missing[] = 'the publishable key';
        } elseif ($mode !== null && ! str_starts_with($publishable, "pk_{$mode}_")) {
            $missing[] = 'a publishable key of the same mode as the secret key';
        }

        if ($settings->webhookSecret() === null) {
            $missing[] = 'the webhook signing secret';
        }

        return $missing;
    }

    /** @return Outcome */
    private function admin(): array
    {
        return User::query()->where('platform_role', 'super_admin')->where('status', '!=', 'suspended')->exists()
            ? ['pass', 'An active super admin exists.']
            : ['fail', "No active super admin. Run php artisan qistas:make-admin with the owner's e-mail address."];
    }

    /** @return Outcome */
    private function support(): array
    {
        return trim((string) config('qistas.support_email')) !== ''
            ? ['pass', 'QISTAS_SUPPORT_EMAIL is set.']
            : ['warn', 'QISTAS_SUPPORT_EMAIL is not set, so suspended people are not told where to write. Set it.'];
    }

    /** @return Outcome */
    private function legal(): array
    {
        $answers = ['/terms' => $this->answer('/terms'), '/privacy' => $this->answer('/privacy')];

        if ($answers === ['/terms' => 200, '/privacy' => 200]) {
            return ['pass', '/terms and /privacy both answer 200.'];
        }

        $said = implode(' and ', array_map(fn (string $path, int $status) => "{$path} answered {$status}", array_keys($answers), $answers));

        return ['fail', "{$said}. Both pages must work before launch."];
    }

    /** @return Outcome */
    private function database(): array
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'pgsql'
            ? ['pass', 'The database is PostgreSQL.']
            : ['warn', "The database is {$driver}, not PostgreSQL. Point the live site at PostgreSQL (DB_URL)."];
    }

    /** @return Outcome */
    private function storage(): array
    {
        $folders = ['storage' => storage_path(), 'storage/logs' => storage_path('logs'), 'storage/framework' => storage_path('framework')];
        $unwritable = array_keys(array_filter($folders, fn (string $path) => ! is_dir($path) || ! is_writable($path)));

        return $unwritable === []
            ? ['pass', 'storage, storage/logs and storage/framework are writable.']
            : ['fail', 'The server cannot write to '.implode(', ', $unwritable).'. Fix its permissions so logs and caches can be saved.'];
    }

    /**
     * What the application answers for [$path], asked of the application itself through its HTTP kernel (no network).
     * The session lives in memory for this one request so none is saved, and the request, the session driver and the
     * per-request objects the application keeps are put back as they were.
     */
    private function answer(string $path): int
    {
        $app = app();
        $before = $app->bound('request') ? $app->make('request') : null;
        $driver = config('session.driver');
        config(['session.driver' => 'array']);

        try {
            $base = rtrim(trim((string) config('app.url')), '/');
            $request = Request::create(($base !== '' ? $base : 'http://localhost').$path);

            return $app->make(HttpKernel::class)->handle($request)->getStatusCode();
        } finally {
            config(['session.driver' => $driver]);

            if ($before !== null) {
                $app->instance('request', $before);
                Facade::clearResolvedInstance('request');
            }

            $app->forgetScopedInstances();
        }
    }

    /**
     * The cleanup's plan, made once however many checks read it.
     *
     * @return Plan
     */
    private function plan(): array
    {
        return $this->plan ??= app(DemoCleanup::class)->plan(true);
    }

    private function count(int $number, string $noun): string
    {
        return $number.' '.$noun.($number === 1 ? '' : 's');
    }
}
