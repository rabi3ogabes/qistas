<?php

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

const BREACHED = 'Welcome#12345';

/** What the breach service answers for a password: the hash suffix and how often it was seen. */
function breachList(string $password, int $seen = 5000): string
{
    return substr(strtoupper(sha1($password)), 5).":{$seen}\r\n";
}

function passwordErrors(string $password): array
{
    return Validator::make(['password' => $password], ['password' => [Password::default()]])->errors()->get('password');
}

it('does not call the breach service while testing', function () {
    Http::fake();

    expect(passwordErrors(BREACHED))->toBe([]);
    Http::assertNothingSent();
});

describe('outside the test environment', function () {
    beforeEach(fn () => $this->app['env'] = 'production');

    it('rejects a password that appears in known data leaks', function () {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(breachList(BREACHED))]);

        expect(passwordErrors(BREACHED))->not->toBe([]);
    });

    it('accepts a password that is not in any leak', function () {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(breachList('Something#Else99'))]);

        expect(passwordErrors(BREACHED))->toBe([]);
    });

    it('sends only the first five characters of the hash, never the password', function () {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        passwordErrors(BREACHED);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/range/'.substr(strtoupper(sha1(BREACHED)), 0, 5))
            && ! str_contains($request->url(), BREACHED));
    });

    it('still enforces the basic rules when the breach service is down', function () {
        Http::fake(fn () => Http::response('', 503));

        expect(passwordErrors('short1!A'))->not->toBe([]);
    });

    it('lets people sign up when the breach service is down', function (Closure $outage) {
        Http::fake($outage);

        expect(passwordErrors(BREACHED))->toBe([]);
    })->with([
        'server error' => [fn () => Http::response('', 500)],
        'unreachable' => [fn () => throw new ConnectionException('unreachable')],
    ]);

    it('waits at most three seconds for the breach service', function () {
        $timeout = (fn () => $this->timeout)->call(app(UncompromisedVerifier::class));

        expect($timeout)->toBe(3);
    });
});
