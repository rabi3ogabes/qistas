<?php

use App\Console\Commands\SetupCommand;
use App\Entitlements\Feature;
use App\Models\PlatformFeature;
use App\Models\PlatformSetting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
    putenv('TRUSTED_PROXIES');
    TrustProxies::flushState();
});

/** The sitemap lists absolute URLs, so it shows which scheme the app believes the visitor used. */
function sitemapAs(string $proxyHeader): string
{
    return test()->get('/sitemap.xml', ['X-Forwarded-Proto' => $proxyHeader, 'X-Forwarded-Host' => 'qistas.example'])->assertOk()->getContent();
}

function trustProxies(string $value): void
{
    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
    putenv("TRUSTED_PROXIES={$value}");
    test()->refreshApplication();
}

describe('the database set-up command', function () {
    it('succeeds on a database that is already up to date, and changes nothing', function () {
        $before = DB::table('migrations')->count();

        $this->artisan('qistas:setup')->assertSuccessful();

        expect(DB::table('migrations')->count())->toBe($before);
    });

    it('creates the demo workspace on request, once', function () {
        $this->artisan('qistas:setup', ['--demo' => true])->assertSuccessful();
        $this->artisan('qistas:setup', ['--demo' => true])->assertSuccessful();

        expect(User::where('email', DemoSeeder::EMAIL)->count())->toBe(1);
    });

    it('starts fast: a release that already set the database up does nothing again, a new release does it all', function () {
        $switch = fn () => PlatformFeature::query()->whereKey(Feature::AiReading->value)->exists();

        $this->artisan('qistas:setup')->assertSuccessful();
        PlatformFeature::query()->whereKey(Feature::AiReading->value)->delete();

        // The same release starting another copy: one look at the fingerprint, nothing more.
        $this->artisan('qistas:setup')->expectsOutputToContain('already set up')->assertSuccessful();
        expect($switch())->toBeFalse();

        // Asked to, or for a release with other migrations or features, everything runs.
        $this->artisan('qistas:setup', ['--force' => true])->assertSuccessful();
        expect($switch())->toBeTrue();

        PlatformFeature::query()->whereKey(Feature::AiReading->value)->delete();
        PlatformSetting::query()->whereKey(SetupCommand::FINGERPRINT)->update(['value' => 'an older release']);
        $this->artisan('qistas:setup')->assertSuccessful();
        expect($switch())->toBeTrue();
    });

    it('creates no demo data unless asked', function () {
        $this->artisan('qistas:setup')->assertSuccessful();

        expect(User::where('email', DemoSeeder::EMAIL)->exists())->toBeFalse();
    });
});

describe('running behind a proxy', function () {
    it('does not believe forwarded headers unless the proxy is trusted', function () {
        expect(sitemapAs('https'))->toContain('http://')->not->toContain('https://qistas.example');
    });

    it('knows the visitor used https when the proxy is trusted', function () {
        trustProxies('*');

        expect(sitemapAs('https'))->toContain('https://qistas.example');
    });

    it('trusts only the proxies it is told about', function () {
        trustProxies('10.1.2.3');

        // The test client connects from 127.0.0.1, which is not on the list.
        expect(sitemapAs('https'))->not->toContain('https://qistas.example');
    });

    it('reads a list of proxies', function () {
        trustProxies('10.1.2.3, 127.0.0.1');

        expect(config('qistas.trusted_proxies'))->toBe(['10.1.2.3', '127.0.0.1'])
            ->and(sitemapAs('https'))->toContain('https://qistas.example');
    });
});
