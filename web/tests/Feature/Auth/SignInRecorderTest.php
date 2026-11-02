<?php

use App\Auth\SignInRecorder;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const SIGN_IN_RECORDER_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const SIGN_IN_RECORDER_DART = 'Dart/3.5 (dart:io)';

/**
 * The sign-in request of a person: what the form posts (including what they typed), the connection and the browser.
 *
 * @param  array<string, mixed>  $input
 * @param  array<string, string>  $headers
 */
function signInRecorderRequest(array $input = [], array $headers = [], string $ip = '203.0.113.9', string $userAgent = SIGN_IN_RECORDER_IPHONE): Request
{
    $request = Request::create('/login', 'POST', $input, [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

beforeEach(function () {
    config(['qistas.geo_header' => 'CF-IPCountry', 'qistas.proxy_chain_allowed' => 2]);

    $this->recorder = app(SignInRecorder::class);
    $this->user = User::factory()->create();
});

describe('a successful sign-in', function () {
    it('writes one row with every field', function () {
        $this->travelTo('2026-11-02 09:30:00');

        $event = $this->recorder->success($this->user, signInRecorderRequest(
            ['email' => $this->user->email, 'password' => 'typed-secret', 'timezone' => 'Asia/Riyadh'],
            ['CF-IPCountry' => 'SA'],
        ));

        $row = SignInEvent::query()->sole();

        expect($row->is($event))->toBeTrue()
            ->and($row->getAttributes())->toEqual([
                'id' => $row->id,
                'user_id' => $this->user->id,
                'method' => 'password',
                'channel' => 'web',
                'outcome' => 'success',
                'device_type' => 'mobile',
                'os' => 'iOS',
                'browser' => 'Mobile Safari',
                'app_version' => null,
                'device_name' => null,
                'ip' => '203.0.113.9',
                'ip_country' => 'SA',
                'proxy' => 'not_detected',
                'proxy_signals' => '[]',
                'user_agent' => SIGN_IN_RECORDER_IPHONE,
                'created_at' => '2026-11-02 09:30:00',
            ])
            ->and($row->proxy_signals)->toBe([])
            ->and(Str::isUuid($row->id))->toBeTrue();
    });

    it('records the method it was given', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest(), 'otp');

        expect($event->method)->toBe('otp')->and($event->channel)->toBe('web');
    });

    it('records the proxy verdict and its reasons', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest(
            ['timezone' => 'Asia/Dubai'],
            ['CF-IPCountry' => 'SA', 'Via' => '1.1 proxy.example.net'],
        ));

        expect(SignInEvent::query()->sole()->only(['proxy', 'proxy_signals', 'ip_country']))
            ->toBe(['proxy' => 'detected', 'proxy_signals' => ['via_header', 'timezone_country_mismatch'], 'ip_country' => 'SA'])
            ->and($event->proxy)->toBe('detected');
    });

    it('records a lone time zone mismatch as possible', function () {
        $this->recorder->success($this->user, signInRecorderRequest(['timezone' => 'Europe/Paris'], ['CF-IPCountry' => 'SA']));

        expect(SignInEvent::query()->sole()->only(['proxy', 'proxy_signals']))
            ->toBe(['proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch']]);
    });

    it('reads the phone\'s offset from UTC when it sends no zone, as text or as a number', function (mixed $offset) {
        $this->recorder->success($this->user, signInRecorderRequest(['utc_offset' => $offset], ['CF-IPCountry' => 'SA'], userAgent: SIGN_IN_RECORDER_DART), channel: 'app');

        expect(SignInEvent::query()->sole()->proxy_signals)->toBe(['timezone_country_mismatch']);
    })->with(['a number' => 240, 'text' => '240']);

    it('takes the phone app\'s own account of its device on the app channel', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest(
            ['device' => ['type' => 'tablet', 'os' => 'android', 'model' => 'SM-X200', 'app_version' => '1.29.0'], 'utc_offset' => 180],
            ['CF-IPCountry' => 'SA'],
            userAgent: SIGN_IN_RECORDER_DART,
        ), 'password', 'app');

        expect($event->only(['channel', 'device_type', 'os', 'browser', 'device_name', 'app_version', 'proxy']))->toBe([
            'channel' => 'app', 'device_type' => 'tablet', 'os' => 'android', 'browser' => null,
            'device_name' => 'SM-X200', 'app_version' => '1.29.0', 'proxy' => 'not_detected',
        ]);
    });

    it('ignores the app\'s account of its device when the sign-in is a web one', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest(['device' => ['type' => 'tablet', 'app_version' => '9.9.9']]));

        expect($event->only(['device_type', 'app_version']))->toBe(['device_type' => 'mobile', 'app_version' => null]);
    });

    it('never fails on inputs of the wrong type: it ignores them', function (array $input) {
        $event = $this->recorder->success($this->user, signInRecorderRequest($input, ['CF-IPCountry' => 'SA']));

        expect($event->only(['outcome', 'device_type', 'proxy']))->toBe(['outcome' => 'success', 'device_type' => 'mobile', 'proxy' => 'not_detected']);
    })->with([
        'a list as the zone' => [['timezone' => ['Asia/Dubai']]],
        'a number as the zone' => [['timezone' => 7]],
        'text as the offset' => [['utc_offset' => 'abc']],
        'a decimal as the offset' => [['utc_offset' => '2.5']],
        'a list as the offset' => [['utc_offset' => [180]]],
        'a huge offset' => [['utc_offset' => '999999999999999999999']],
        'text as the device' => [['device' => 'tablet']],
        'a number as the device' => [['device' => 3]],
        'a zone that is not one' => [['timezone' => str_repeat('x', 5000)]],
    ]);

    it('writes the address of the connection, and 0.0.0.0 when there is none', function () {
        $this->recorder->success($this->user, signInRecorderRequest(ip: '2001:db8::7'));

        $console = signInRecorderRequest();
        $console->server->remove('REMOTE_ADDR');
        $this->recorder->success($this->user, $console);

        expect(SignInEvent::query()->orderBy('created_at')->orderBy('ip')->pluck('ip')->all())->toEqual(['0.0.0.0', '2001:db8::7']);
    });

    it('refuses a channel that is not web or app', function () {
        expect(fn () => $this->recorder->success($this->user, signInRecorderRequest(), 'password', 'sms'))
            ->toThrow(InvalidArgumentException::class)
            ->and(SignInEvent::query()->count())->toBe(0);
    });

    it('is not throttled: every real sign-in is a row', function () {
        $this->recorder->success($this->user, signInRecorderRequest());
        $this->recorder->success($this->user, signInRecorderRequest());

        expect(SignInEvent::query()->where('outcome', 'success')->count())->toBe(2);
    });
});

describe('a failed sign-in', function () {
    it('writes one row, outcome failed, with the same details', function () {
        $event = $this->recorder->failed($this->user, signInRecorderRequest(['email' => $this->user->email, 'password' => 'wrong'], ['CF-IPCountry' => 'SA']));

        $row = SignInEvent::query()->sole();

        expect($event)->toBeInstanceOf(SignInEvent::class)
            ->and($row->is($event))->toBeTrue()
            ->and($row->only(['user_id', 'method', 'channel', 'outcome', 'device_type', 'ip', 'ip_country', 'proxy']))->toBe([
                'user_id' => $this->user->id, 'method' => 'password', 'channel' => 'web', 'outcome' => 'failed',
                'device_type' => 'mobile', 'ip' => '203.0.113.9', 'ip_country' => 'SA', 'proxy' => 'not_detected',
            ]);
    });

    it('writes nothing for a second failure of the same account from the same address within a minute', function () {
        $first = $this->recorder->failed($this->user, signInRecorderRequest());
        $second = $this->recorder->failed($this->user, signInRecorderRequest());

        expect($first)->not->toBeNull()
            ->and($second)->toBeNull()
            ->and(SignInEvent::query()->count())->toBe(1);

        $this->travel(59)->seconds();

        expect($this->recorder->failed($this->user, signInRecorderRequest()))->toBeNull()
            ->and(SignInEvent::query()->count())->toBe(1);
    });

    it('writes for a different address', function () {
        $this->recorder->failed($this->user, signInRecorderRequest(ip: '203.0.113.9'));
        $other = $this->recorder->failed($this->user, signInRecorderRequest(ip: '198.51.100.7'));

        expect($other)->not->toBeNull()->and(SignInEvent::query()->count())->toBe(2);
    });

    it('writes again after 61 seconds', function () {
        $this->recorder->failed($this->user, signInRecorderRequest());

        $this->travel(61)->seconds();

        expect($this->recorder->failed($this->user, signInRecorderRequest()))->not->toBeNull()
            ->and(SignInEvent::query()->count())->toBe(2);

        // And that second row throttles the next one in turn.
        expect($this->recorder->failed($this->user, signInRecorderRequest()))->toBeNull();
    });

    it('throttles each account on its own: the same address failing against another account writes', function () {
        $someoneElse = User::factory()->create();

        $this->recorder->failed($this->user, signInRecorderRequest());

        expect($this->recorder->failed($someoneElse, signInRecorderRequest()))->not->toBeNull()
            ->and(SignInEvent::query()->count())->toBe(2);
    });

    it('is not held back by a success, and does not hold one back', function () {
        $this->recorder->success($this->user, signInRecorderRequest());

        expect($this->recorder->failed($this->user, signInRecorderRequest()))->not->toBeNull();

        expect($this->recorder->success($this->user, signInRecorderRequest()))->toBeInstanceOf(SignInEvent::class)
            ->and(SignInEvent::query()->count())->toBe(3);
    });

    it('keeps a stranger from filling a history: a hundred wrong guesses in a minute are one row', function () {
        foreach (range(1, 100) as $guess) {
            $this->recorder->failed($this->user, signInRecorderRequest(['password' => "guess-{$guess}"]));
        }

        expect(SignInEvent::query()->count())->toBe(1);
    });
});

describe('what is never stored', function () {
    it('has no column for an e-mail, a phone, a password or a token', function () {
        $columns = Schema::getColumnListing('sign_in_events');

        sort($columns);

        expect($columns)->toBe([
            'app_version', 'browser', 'channel', 'created_at', 'device_name', 'device_type', 'id', 'ip', 'ip_country',
            'method', 'os', 'outcome', 'proxy', 'proxy_signals', 'user_agent', 'user_id',
        ]);
    });

    it('never lets what was typed reach any stored value', function () {
        $typed = [
            'email' => 'typed.by.a.stranger@example.org', 'phone' => '+966500000042', 'login' => 'stranger',
            'password' => 'hunter2-typed', 'code' => '123456', 'token' => 'tok_secret_value',
        ];

        $this->recorder->success($this->user, signInRecorderRequest($typed));
        $this->travel(61)->seconds();
        $this->recorder->failed($this->user, signInRecorderRequest($typed));

        $stored = SignInEvent::query()->get()->map(fn (SignInEvent $row) => json_encode($row->getAttributes()))->implode("\n");

        expect(SignInEvent::query()->count())->toBe(2);

        foreach ($typed as $value) {
            expect($stored)->not->toContain($value);
        }
    });
});

describe('the record itself', function () {
    it('refuses to be updated or deleted', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest());

        expect(fn () => $event->update(['ip' => '198.51.100.1']))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class)
            ->and(fn () => SignInEvent::query()->sole()->forceFill(['os' => 'changed'])->save())->toThrow(LogicException::class);

        expect(SignInEvent::query()->sole()->only(['ip', 'os']))->toBe(['ip' => '203.0.113.9', 'os' => 'iOS']);
    });

    it('has a creation time and no update time', function () {
        $event = $this->recorder->success($this->user, signInRecorderRequest());

        expect(SignInEvent::UPDATED_AT)->toBeNull()
            ->and(Schema::hasColumn('sign_in_events', 'updated_at'))->toBeFalse()
            ->and($event->created_at)->not->toBeNull();
    });

    it('belongs to a real person: a row for nobody is refused by the database', function () {
        $insert = fn () => SignInEvent::query()->forceCreate([
            'user_id' => (string) Str::uuid(), 'method' => 'password', 'channel' => 'web', 'outcome' => 'success',
            'device_type' => 'unknown', 'ip' => '203.0.113.9', 'proxy' => 'not_detected', 'proxy_signals' => [],
        ]);

        expect($insert)->toThrow(QueryException::class);
    })->group('db-errors'); // makes the database raise an error: left out when running against the PGlite stand-in

    it('is not tenant-scoped: it needs no workspace to be read', function () {
        $this->recorder->success($this->user, signInRecorderRequest());

        // No workspace is active in this test, and a tenant-owned model matches nothing without one.
        expect(SignInEvent::query()->count())->toBe(1);
    });
});

describe('a person\'s sign-ins', function () {
    it('are only theirs, newest first', function () {
        $someoneElse = User::factory()->create();

        $this->travelTo('2026-11-02 08:00:00');
        $oldest = $this->recorder->success($this->user, signInRecorderRequest());
        $this->travel(10)->seconds();
        $this->recorder->success($someoneElse, signInRecorderRequest());
        $this->travel(10)->seconds();
        $middle = $this->recorder->failed($this->user, signInRecorderRequest(ip: '198.51.100.7'));
        $this->travel(10)->seconds();
        $newest = $this->recorder->success($this->user, signInRecorderRequest());

        expect($this->user->signInEvents()->pluck('id')->all())->toBe([$newest->id, $middle->id, $oldest->id])
            ->and($someoneElse->signInEvents()->count())->toBe(1)
            ->and($this->user->signInEvents->first())->toBeInstanceOf(SignInEvent::class);
    });

    it('can be limited, as the screens do to the last twenty', function () {
        foreach (range(1, 25) as $i) {
            $this->recorder->success($this->user, signInRecorderRequest());
            $this->travel(1)->second();
        }

        expect($this->user->signInEvents()->limit(20)->get())->toHaveCount(20);
    });

    it('go with the person: deleting them removes their rows and only theirs', function () {
        $someoneElse = User::factory()->create();

        $this->recorder->success($this->user, signInRecorderRequest());
        $this->recorder->failed($this->user, signInRecorderRequest());
        $this->recorder->success($someoneElse, signInRecorderRequest());

        $this->user->delete();

        expect(SignInEvent::query()->count())->toBe(1)
            ->and(SignInEvent::query()->sole()->user_id)->toBe($someoneElse->id);
    });
});
