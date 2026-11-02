<?php

use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Entitlements\Feature;
use App\Mail\MailSettings;
use App\Models\CronRun;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Sandbox\TestWorkspace;
use App\Support\GoLiveCheck;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| `qistas:go-live-check` (go-live basics 6.3): sixteen checks the owner runs on the server before real customers come,
| each PASS, WARN or FAIL with one plain line saying what to do next. It reads, it never writes, and it never prints a
| secret, a customer's address or the value of a setting: a setting is named, not shown.
*/

beforeEach(function () {
    $this->travelTo('2026-11-02 09:00:00');
    goLiveReady();
});

/** The checks, in the order the owner reads them. */
const GO_LIVE_KEYS = ['env', 'debug', 'url', 'demo', 'demo-data', 'demo-domain', 'test-workspaces', 'mail', 'cron', 'geo', 'billing', 'admin', 'support', 'legal', 'database', 'storage'];

/** Everything a site that is ready has: production settings, e-mail, a recent scheduler run and a super admin. */
function goLiveReady(): void
{
    config([
        'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://qistas.example',
        'session.secure' => true, 'qistas.demo' => false,
        'mail.server_mailer' => 'smtp', 'mail.default' => 'smtp', 'mail.from.address' => 'hello@qistas.example',
        'qistas.geo_header' => 'X-Vercel-IP-Country', 'qistas.support_email' => 'support@qistas.example',
    ]);
    goLiveRun(minutesAgo: 3);
    User::factory()->create(['platform_role' => 'super_admin', 'email' => 'owner@qistas.example']);
}

/** One call of the cron endpoint, [$minutesAgo] minutes ago. */
function goLiveRun(int $minutesAgo, string $outcome = 'ok'): CronRun
{
    return CronRun::create(['started_at' => now()->subMinutes($minutesAgo), 'finished_at' => now()->subMinutes($minutesAgo), 'outcome' => $outcome, 'jobs' => 2]);
}

/** @return array<string, string> status by key */
function goLiveStatuses(): array
{
    return collect(GoLiveCheck::run())->pluck('status', 'key')->all();
}

/** @return array{key: string, status: string, title: string, detail: string} */
function goLiveResult(string $key): array
{
    return collect(GoLiveCheck::run())->firstWhere('key', $key);
}

/** The status every check has on a site that is ready: the database is the only one that depends on the engine. */
function goLiveBaseline(): array
{
    return array_merge(array_fill_keys(GO_LIVE_KEYS, 'pass'), ['database' => DB::connection()->getDriverName() === 'pgsql' ? 'pass' : 'warn']);
}

/** A demo account as the old demo button made it. */
function goLiveDemoAccount(): User
{
    return User::factory()->create(['email' => 'demo-'.Str::lower(Str::random(12)).'@demo.qistas.test', 'demo_expires_at' => now()->addHours(12)]);
}

/** Every table's row count, to show that a run changed nothing. */
function goLiveRowCounts(): array
{
    return collect(Schema::getTables(Schema::getCurrentSchemaName()))->pluck('name')->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

/** Makes one thing wrong, named as in the data set below. */
function goLiveBreak(string $what): void
{
    match ($what) {
        'env-local' => config(['app.env' => 'local']),
        'debug-on' => config(['app.debug' => true]),
        'url-http' => config(['app.url' => 'http://qistas.example']),
        'cookie-not-secure' => config(['session.secure' => false]),
        'cookie-unset' => config(['session.secure' => null]),
        'cookie-one' => config(['session.secure' => '1']),
        'cookie-on' => config(['session.secure' => 'on']),
        'cookie-zero' => config(['session.secure' => '0']),
        'demo-mode-on' => config(['qistas.demo' => true]),
        'demo-account' => goLiveDemoAccount(),
        'lookalike' => User::factory()->create(['email' => 'layla@demo.qistas.test']),
        'test-workspace' => app(TestWorkspace::class)->open(User::where('email', 'owner@qistas.example')->sole()),
        'mail-log' => config(['mail.server_mailer' => 'log', 'mail.default' => 'log']),
        'mail-array' => config(['mail.server_mailer' => 'array', 'mail.default' => 'array']),
        'mail-null' => config(['mail.server_mailer' => 'null', 'mail.default' => 'null']),
        'mail-unset' => config(['mail.server_mailer' => null, 'mail.default' => 'log']),
        'mail-no-from' => config(['mail.from.address' => '']),
        'mail-undefined' => config(['mail.server_mailer' => 'smpt', 'mail.default' => 'smpt']),
        'mail-dsn' => config(['mail.server_mailer' => 'smtp://mailer:s3cret-zqx@mail.zqx-host.example:587', 'mail.default' => 'smtp://mailer:s3cret-zqx@mail.zqx-host.example:587']),
        'mail-ses' => config(['mail.server_mailer' => 'ses', 'mail.default' => 'ses']),
        'cron-none' => CronRun::query()->delete(),
        'cron-old' => CronRun::query()->update(['started_at' => now()->subMinutes(16)]),
        'cron-failed' => CronRun::query()->update(['outcome' => 'failed']),
        'geo-unset' => config(['qistas.geo_header' => null]),
        'card-fake' => goLiveCardOn(fake: true),
        'card-unconfigured' => goLiveCardOn(),
        'card-test-keys' => goLiveCardOn(keys: 'test'),
        'card-no-publishable' => goLiveCardOn(keys: 'live', publishable: false),
        'card-no-webhook' => goLiveCardOn(keys: 'live', webhook: false),
        'card-mixed-keys' => goLiveCardOn(keys: 'live', publishableMode: 'test'),
        'no-admin' => User::query()->update(['platform_role' => null]),
        'suspended-admin' => User::query()->update(['status' => 'suspended']),
        'plain-admin' => User::query()->update(['platform_role' => 'admin']),
        'no-support' => config(['qistas.support_email' => null]),
        'terms-gone' => Route::get('/terms', fn () => abort(404)),
        'privacy-down' => Route::get('/privacy', fn () => response('Back soon', 503)),
        'privacy-redirect' => Route::get('/privacy', fn () => redirect('/elsewhere')),
    };
}

/**
 * Pro by card switched on, with the fake gateway, with no Stripe keys, or with Stripe keys of this mode: the secret key,
 * the publishable key (of [$publishableMode] when that differs) and the webhook signing secret, each left out on request.
 */
function goLiveCardOn(bool $fake = false, ?string $keys = null, bool $publishable = true, bool $webhook = true, ?string $publishableMode = null): void
{
    switchOn(Feature::CardBilling);

    if ($fake) {
        app()->instance(StripeGateway::class, new FakeStripeGateway);
    }

    if ($keys !== null) {
        config([
            'qistas.billing.stripe.secret' => "sk_{$keys}_abcdef123456",
            'qistas.billing.stripe.publishable' => $publishable ? 'pk_'.($publishableMode ?? $keys).'_abcdef123456' : null,
            'qistas.billing.stripe.webhook_secret' => $webhook ? 'whsec_abcdef123456' : null,
        ]);
    }
}

/** The owner's SMTP server as /admin/mail saves it, with the server's own settings put back as the environment has them. */
function goLiveSavedServer(string $from = 'hello@saved.example'): void
{
    app(MailSettings::class)->save([
        'host' => 'smtp.zqx-host.example', 'port' => 587, 'security' => 'starttls', 'username' => 'zqx-mailer',
        'password' => 'smtp-password-Zx81', 'from_address' => $from, 'from_name' => 'Qistas',
    ], null);
    config(['mail.server_mailer' => null, 'mail.default' => 'log', 'mail.from.address' => '']);
}

describe('the results', function () {
    it('has one result for each check, in the order the owner reads them, in plain words', function () {
        $results = GoLiveCheck::run();

        expect(array_column($results, 'key'))->toBe(GO_LIVE_KEYS);

        foreach ($results as $result) {
            expect(array_keys($result))->toBe(['key', 'status', 'title', 'detail'])
                ->and($result['status'])->toBeIn(['pass', 'warn', 'fail'])
                ->and($result['title'])->toBeString()->not->toBe('')
                ->and($result['detail'])->toBeString()->not->toBe('')->not->toContain("\n");
        }
    });

    it('passes every check it can on a site that is ready', function () {
        expect(goLiveStatuses())->toBe(goLiveBaseline());
    });

    it('does not leave the HTTP request, the session driver or a session behind', function () {
        config(['session.driver' => 'database']);
        $request = app('request');

        GoLiveCheck::run();

        expect(app('request'))->toBe($request)
            ->and(config('session.driver'))->toBe('database')
            ->and(DB::table('sessions')->count())->toBe(0);
    });

    it('turns a check that cannot run into a failure, without a word of what went wrong inside', function () {
        // The saved mail password was written under another APP_KEY: reading it throws, as a real fault would.
        Exceptions::fake();
        config(['mail.server_mailer' => null]);
        PlatformSetting::query()->forceCreate(['key' => MailSettings::KEY, 'value' => json_encode([
            'host' => 'smtp.zqx-host.example', 'port' => 587, 'password' => 'zqx-not-encrypted', 'from_address' => 'hello@qistas.example',
        ])]);

        $results = collect(GoLiveCheck::run())->keyBy('key');

        expect($results['mail']['status'])->toBe('fail')
            ->and($results['mail']['detail'])->toContain('could not run')->not->toContain('zqx')->not->toContain('payload')
            ->and($results['env']['status'])->toBe('pass')
            ->and($results['cron']['status'])->toBe('pass')
            ->and($results)->toHaveCount(count(GO_LIVE_KEYS));

        // The reason goes to the log through the exception handler, not to the screen.
        Exceptions::assertReported(DecryptException::class);
    });
});

describe('what each check looks at', function () {
    it('changes only its own check', function (string $what, string $key, string $status, string $mention, string $never = '') {
        goLiveBreak($what);

        $results = collect(GoLiveCheck::run())->keyBy('key');

        expect($results[$key]['status'])->toBe($status)
            ->and($results[$key]['detail'])->toContain($mention)
            ->and($results->pluck('status', 'key')->except($key)->all())->toBe(Arr::except(goLiveBaseline(), $key));

        // What was typed into a setting is never repeated back.
        if ($never !== '') {
            expect(json_encode($results->all()))->not->toContain($never);
        }
    })->with([
        'APP_ENV is not production' => ['env-local', 'env', 'fail', 'APP_ENV'],
        'debug is on' => ['debug-on', 'debug', 'fail', 'APP_DEBUG'],
        'APP_URL is not https' => ['url-http', 'url', 'fail', 'https://'],
        'the session cookie is not secure' => ['cookie-not-secure', 'url', 'warn', 'SESSION_SECURE_COOKIE'],
        'the session cookie setting is missing' => ['cookie-unset', 'url', 'warn', 'SESSION_SECURE_COOKIE'],
        'the session cookie setting is written as 1' => ['cookie-one', 'url', 'pass', 'cookie is secure'],
        'the session cookie setting is written as on' => ['cookie-on', 'url', 'pass', 'cookie is secure'],
        'the session cookie setting is written as 0' => ['cookie-zero', 'url', 'warn', 'SESSION_SECURE_COOKIE'],
        'the built-in demo mode is on' => ['demo-mode-on', 'demo', 'fail', 'QISTAS_DEMO'],
        'an old demo account exists' => ['demo-account', 'demo-data', 'fail', 'php artisan qistas:purge-demo'],
        'an account at the demo domain that the old demo did not make' => ['lookalike', 'demo-domain', 'warn', '1 account'],
        'a staff test workspace exists' => ['test-workspace', 'test-workspaces', 'warn', 'php artisan qistas:purge-demo --include-test'],
        'the mailer is log' => ['mail-log', 'mail', 'fail', 'MAIL_MAILER'],
        'the mailer is array' => ['mail-array', 'mail', 'fail', 'MAIL_MAILER'],
        'the mailer is null' => ['mail-null', 'mail', 'fail', 'MAIL_MAILER'],
        'no mailer is chosen anywhere' => ['mail-unset', 'mail', 'fail', 'MAIL_MAILER'],
        'the from address is empty' => ['mail-no-from', 'mail', 'fail', 'MAIL_FROM_ADDRESS'],
        'the mailer is misspelt' => ['mail-undefined', 'mail', 'fail', 'MAIL_MAILER', 'smpt'],
        'the mailer is a connection string with a password' => ['mail-dsn', 'mail', 'fail', 'MAIL_MAILER', 'zqx'],
        'the mailer is another defined one' => ['mail-ses', 'mail', 'pass', 'ses'],
        'the scheduler never ran' => ['cron-none', 'cron', 'warn', 'CRON_SECRET'],
        'the scheduler last ran 16 minutes ago' => ['cron-old', 'cron', 'fail', 'php artisan qistas:cron-status'],
        'the scheduler last run failed' => ['cron-failed', 'cron', 'warn', 'php artisan qistas:cron-status'],
        'the CDN country header is not set' => ['geo-unset', 'geo', 'warn', 'QISTAS_GEO_HEADER'],
        'card billing is on with the fake gateway' => ['card-fake', 'billing', 'warn', 'fake gateway'],
        'card billing is on with no Stripe keys' => ['card-unconfigured', 'billing', 'warn', 'no Stripe keys'],
        'card billing is on with Stripe test keys' => ['card-test-keys', 'billing', 'warn', 'test keys'],
        'card billing is on with no publishable key' => ['card-no-publishable', 'billing', 'warn', 'the publishable key', 'abcdef123456'],
        'card billing is on with no webhook signing secret' => ['card-no-webhook', 'billing', 'warn', 'the webhook signing secret', 'abcdef123456'],
        'card billing is on with keys of two modes' => ['card-mixed-keys', 'billing', 'warn', 'same mode as the secret key', 'abcdef123456'],
        'there is no super admin' => ['no-admin', 'admin', 'fail', 'php artisan qistas:make-admin'],
        'the only super admin is suspended' => ['suspended-admin', 'admin', 'fail', 'php artisan qistas:make-admin'],
        'the only admin is not a super admin' => ['plain-admin', 'admin', 'fail', 'php artisan qistas:make-admin'],
        'the support e-mail is not set' => ['no-support', 'support', 'warn', 'QISTAS_SUPPORT_EMAIL'],
        'the terms page is gone' => ['terms-gone', 'legal', 'fail', '/terms answered 404'],
        'the privacy page is down' => ['privacy-down', 'legal', 'fail', '/privacy answered 503'],
        'the privacy page redirects' => ['privacy-redirect', 'legal', 'fail', '/privacy answered 302'],
    ]);

    it('names a good setting by its name, so the owner knows what passed', function () {
        $results = collect(GoLiveCheck::run())->keyBy('key');

        expect($results['env']['detail'])->toContain('APP_ENV')
            ->and($results['legal']['detail'])->toContain('/terms')->toContain('/privacy');
    });

    it('wants the storage folders to be writable', function () {
        $original = storage_path();
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-missing-'.Str::random(8);
        app()->useStoragePath($missing);

        try {
            $result = goLiveResult('storage');
        } finally {
            app()->useStoragePath($original);
        }

        expect($result['status'])->toBe('fail')
            ->and($result['detail'])->toContain('storage/logs')->not->toContain($missing);
    });

    it('warns when the database is not PostgreSQL', function () {
        $result = goLiveResult('database');

        expect($result['status'])->toBe('warn')->and($result['detail'])->toContain('sqlite')->toContain('PostgreSQL');
    })->skip(fn () => DB::connection()->getDriverName() === 'pgsql', 'the engine under test is PostgreSQL');

    it('passes the database check on PostgreSQL', function () {
        expect(goLiveResult('database')['status'])->toBe('pass');
    })->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL');
});

describe('old demo accounts', function () {
    it('counts what a purge would remove and what it would hold back', function () {
        goLiveDemoAccount();
        goLiveDemoAccount();
        $held = goLiveDemoAccount();
        $shop = Tenant::factory()->create();
        $shop->forceFill(['owner_user_id' => $held->id])->save();
        $shop->users()->attach($held->id, ['role' => 'owner']);
        memberAs('manager', $shop);

        $result = goLiveResult('demo-data');

        expect($result['status'])->toBe('fail')
            ->and($result['detail'])->toContain('2 can be removed')->toContain('1 is held back')->toContain('php artisan qistas:purge-demo');
    });

    it('fails when only a held-back account is left, and says there is nothing to confirm', function () {
        $held = goLiveDemoAccount();
        $shop = Tenant::factory()->create();
        $shop->forceFill(['owner_user_id' => $held->id])->save();
        $shop->users()->attach($held->id, ['role' => 'owner']);
        memberAs('manager', $shop);

        $result = goLiveResult('demo-data');

        expect($result['status'])->toBe('fail')
            ->and($result['detail'])->toContain('0 can be removed')->toContain('1 is held back')->not->toContain('--confirm');
    });

    it('fails for the developer seeder’s account', function () {
        $this->seed(DemoSeeder::class);

        expect(goLiveResult('demo-data')['status'])->toBe('fail');
    });

    it('does not count staff test workspaces, nor real people', function () {
        app(TestWorkspace::class)->open(User::where('email', 'owner@qistas.example')->sole());
        owner();

        expect(goLiveResult('demo-data')['status'])->toBe('pass');
    });

    it('warns about an address at the demo domain that the old demo did not make, by count and never by address', function () {
        User::factory()->create(['email' => 'layla.zqx@demo.qistas.test']);
        User::factory()->create(['email' => 'Sara.Zqx@Demo.Qistas.Test']);
        User::factory()->create(['email' => 'ops.zqx@demo.qistas.test', 'platform_role' => 'admin']);
        goLiveDemoAccount(); // the old demo's own: that is the demo-data check's business

        $result = goLiveResult('demo-domain');

        expect($result['status'])->toBe('warn')
            ->and($result['detail'])->toContain('3 accounts')->not->toContain('zqx')->not->toContain('@demo');
    });

    it('does not warn about the demo domain when only old demo accounts use it', function () {
        goLiveDemoAccount();

        expect(goLiveResult('demo-domain')['status'])->toBe('pass');
    });

    it('counts staff test workspaces, and only by number', function () {
        $first = app(TestWorkspace::class)->open(User::where('email', 'owner@qistas.example')->sole());
        $second = User::factory()->create(['platform_role' => 'admin', 'email' => 'staff.zqx@qistas.example']);
        app(TestWorkspace::class)->open($second);

        $result = goLiveResult('test-workspaces');

        expect($result['status'])->toBe('warn')
            ->and($result['detail'])->toContain('2')->not->toContain('zqx')->not->toContain($first->name);
    });
});

describe('e-mail', function () {
    it('passes with the SMTP server saved in the admin area, whatever the environment says', function () {
        goLiveSavedServer();

        $result = goLiveResult('mail');

        expect($result['status'])->toBe('pass')
            ->and($result['detail'])->toContain('admin E-mail page')
            ->and($result['detail'])->not->toContain('zqx-host')->not->toContain('Zx81')->not->toContain('zqx-mailer');
    });

    it('fails when the saved server has no from address', function () {
        goLiveSavedServer(from: '');

        $result = goLiveResult('mail');

        expect($result['status'])->toBe('fail')->and($result['detail'])->toContain('From address in the admin E-mail page');
    });

    it('lets a real mailer in the server’s environment win over the saved server, as the app does', function () {
        goLiveSavedServer(from: '');
        config(['mail.server_mailer' => 'smtp', 'mail.default' => 'smtp', 'mail.from.address' => 'hello@qistas.example']);

        $result = goLiveResult('mail');

        expect($result['status'])->toBe('pass')->and($result['detail'])->toContain('server environment');
    });

    it('fails for a mailer that is not defined even when an SMTP server is saved, and prints nothing of what was typed', function () {
        goLiveSavedServer();
        $typed = 'smtp://mailer:s3cret-zqx@mail.zqx-host.example:587'; // a connection string pasted into MAIL_MAILER
        config(['mail.server_mailer' => $typed, 'mail.default' => $typed]);

        $exit = Artisan::call('qistas:go-live-check');

        expect(goLiveResult('mail')['status'])->toBe('fail')
            ->and($exit)->toBe(1)
            ->and(Artisan::output())->toMatch('/FAIL\s+E-mail can be sent/')->not->toContain('zqx')->not->toContain('s3cret');
    });

    it('passes for another mailer the application defines, naming it', function () {
        config(['mail.server_mailer' => 'ses', 'mail.default' => 'ses']);

        expect(goLiveResult('mail'))->toMatchArray(['status' => 'pass'])
            ->and(goLiveResult('mail')['detail'])->toContain('through ses');
    });

    it('fails when the from address is not an address', function () {
        config(['mail.from.address' => 'not an address']);

        expect(goLiveResult('mail')['status'])->toBe('fail');
    });
});

describe('scheduled work', function () {
    it('passes at 14 minutes and fails at 16', function () {
        CronRun::query()->update(['started_at' => now()->subMinutes(14)]);
        expect(goLiveResult('cron')['status'])->toBe('pass');

        CronRun::query()->update(['started_at' => now()->subMinutes(16)]);
        expect(goLiveResult('cron')['status'])->toBe('fail');
    });

    it('goes by the newest run, not the oldest', function () {
        CronRun::query()->update(['started_at' => now()->subHours(3)]);
        goLiveRun(minutesAgo: 2);

        expect(goLiveResult('cron')['status'])->toBe('pass');
    });

    it('says how long ago it last ran', function () {
        CronRun::query()->update(['started_at' => now()->subHours(3)]);

        $result = goLiveResult('cron');

        expect($result['status'])->toBe('fail')->and($result['detail'])->toContain('3 hours ago');
    });
});

describe('card billing', function () {
    it('passes with card billing off, whatever the gateway is', function () {
        app()->instance(StripeGateway::class, new FakeStripeGateway);

        expect(goLiveResult('billing')['status'])->toBe('pass');
    });

    it('passes with card billing on and live Stripe keys', function () {
        goLiveCardOn(keys: 'live');

        $result = goLiveResult('billing');

        expect($result['status'])->toBe('pass')->and($result['detail'])->not->toContain('abcdef123456');
    });
});

describe('the terms and privacy pages', function () {
    it('asks the application itself, with no network', function () {
        Http::fake();

        expect(goLiveResult('legal')['status'])->toBe('pass');

        Http::assertNothingSent();
    });

    it('leaves the request it was started with in place', function () {
        $before = app('request');

        GoLiveCheck::run();

        expect(app('request'))->toBe($before);
    });
});

describe('what it never shows', function () {
    it('shows no secret, no customer address and no setting’s value', function () {
        goLiveSavedServer();
        config([
            'qistas.cron.secret' => 'cron-secret-Qw93', 'qistas.billing.stripe.webhook_secret' => 'whsec_Hidden0123',
            'mail.mailers.smtp.password' => 'smtp-password-Zx81',
            'qistas.support_email' => 'support-zqx@qistas.example', 'qistas.geo_header' => 'X-Zqx-Country',
        ]);
        goLiveCardOn(keys: 'live');
        User::factory()->create(['email' => 'customer-zqx@demo.qistas.test']);
        $demo = goLiveDemoAccount();
        goLiveBreak('env-local');
        $key = substr((string) config('app.key'), 7);

        $text = json_encode(GoLiveCheck::run()).Artisan::call('qistas:go-live-check').Artisan::output();

        foreach (['Qw93', 'Hidden0123', 'Zx81', 'zqx', 'abcdef123456', 'qistas.example', $key, $demo->email] as $secret) {
            expect($text)->not->toContain($secret);
        }
    });
});

describe('the command', function () {
    it('prints one line for each check with the words PASS, WARN or FAIL, and a summary', function () {
        goLiveBreak('env-local');
        goLiveBreak('geo-unset');
        $statuses = collect(goLiveStatuses());

        Artisan::call('qistas:go-live-check');
        $output = Artisan::output();

        preg_match_all('/^\s*(PASS|WARN|FAIL)\s+\S/m', $output, $lines);
        expect($lines[0])->toHaveCount(count(GO_LIVE_KEYS))
            ->and(array_count_values($lines[1]) + ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0])->toMatchArray([
                'PASS' => $statuses->filter(fn ($s) => $s === 'pass')->count(),
                'WARN' => $statuses->filter(fn ($s) => $s === 'warn')->count(),
                'FAIL' => 1,
            ])
            ->and($output)->toMatch('/FAIL\s+Production mode/')
            ->and($output)->toContain(sprintf(
                'Summary: %d PASS, %d WARN, %d FAIL',
                $statuses->filter(fn ($s) => $s === 'pass')->count(),
                $statuses->filter(fn ($s) => $s === 'warn')->count(),
                1,
            ));
    });

    it('exits with 1 when a check fails', function () {
        goLiveBreak('debug-on');

        $this->artisan('qistas:go-live-check')->assertExitCode(1);
    });

    it('exits with 0 when there are only warnings', function () {
        goLiveBreak('geo-unset');
        goLiveBreak('no-support');

        $this->artisan('qistas:go-live-check')->assertExitCode(0);
    });

    it('exits with 0 on a site that is ready', function () {
        $this->artisan('qistas:go-live-check')->assertExitCode(0);
    });

    it('changes no data: no table gains or loses a row', function () {
        goLiveDemoAccount();
        app(TestWorkspace::class)->open(User::where('email', 'owner@qistas.example')->sole());
        goLiveBreak('lookalike');
        goLiveBreak('cron-failed');
        $before = goLiveRowCounts();

        $this->artisan('qistas:go-live-check')->assertExitCode(1);

        expect(goLiveRowCounts())->toBe($before);
    });
});
