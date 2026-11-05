<?php

use App\Models\ErrorIssue;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| A bearer token has the shape `<id>|<secret>`, and the id is the number of a row of personal_access_tokens (a bigint).
| Anyone can send any header: a token whose id part is not a positive whole number that a bigint can hold is no token at
| all. It must be answered like any unknown token (401, the API's usual body) and must never reach the database as a
| lookup PostgreSQL refuses (`find('99999999999999999999')` on a bigint is SQLSTATE 22003), which would be a 500 on every
| route behind the sign-in and a `database/exception` issue in the error center, eligible for the instant e-mail.
| Runs on SQLite and on PostgreSQL alike.
*/

beforeEach(function () {
    config(['app.debug' => false, 'logging.default' => 'null']);
});

dataset('errors malformed bearer tokens', [
    'letters for an id' => ['abc|x'],
    'a number and garbage' => ['12|'.'%%<>garbage not a token'],
    'an id over the bigint range' => ['99999999999999999999|x'],
    'one past the largest bigint' => ['9223372036854775808|x'],
    'zero' => ['0|x'],
    'a negative id' => ['-1|x'],
    'an exponent' => ['1e3|x'],
    'a padded id' => [' 12 |x'],
    'no id' => ['|x'],
    'no secret' => ['12|'],
]);

it('a bearer token whose id is not a bigint is nobody: 401 on a signed-in route, and no error issue', function (string $token) {
    $this->withToken($token)->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    expect(ErrorIssue::query()->count())->toBe(0);
})->with('errors malformed bearer tokens');

it('a public route that reads the language from the token still answers with such a token', function (string $token) {
    // No Accept-Language (the test client sends one by default), so SetApiLocale asks the token guard for the language.
    $this->withToken($token)->withHeaders(['Accept-Language' => ''])->getJson('/api/v1/plans')->assertOk();

    expect(ErrorIssue::query()->count())->toBe(0);
})->with('errors malformed bearer tokens');

it('the phone\'s crash reports still take such a token as nobody', function (string $token) {
    $this->withToken($token)->call('POST', '/api/v1/client-errors', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['class' => 'StateError', 'os' => 'android'], JSON_THROW_ON_ERROR))
        ->assertNoContent();

    expect(ErrorIssue::query()->where('kind', 'crash')->sole()->people)->toBe(0)
        ->and(ErrorIssue::query()->where('kind', '!=', 'crash')->count())->toBe(0);
})->with('errors malformed bearer tokens');

it('an id is a row\'s number from 1 to the largest bigint, and nothing else is looked up', function () {
    foreach (['1', '12', '9223372036854775807', '999999999999999999'] as $id) {
        expect(PersonalAccessToken::isId($id))->toBeTrue($id);
    }

    foreach (['', '0', '012', '-1', '+1', '1e3', ' 12', '12 ', "12\n", 'abc', '9223372036854775808', '99999999999999999999', '18446744073709551615'] as $id) {
        expect(PersonalAccessToken::isId($id))->toBeFalse(var_export($id, true));
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(PersonalAccessToken::findToken('99999999999999999999|x'))->toBeNull()
        ->and(PersonalAccessToken::findToken('abc|x'))->toBeNull()
        ->and($queries)->toBe(0);
});

it('Sanctum uses this token model, so every token the app makes and reads is held to the rule', function () {
    [$user] = makeAccount();
    $token = $user->createToken('phone', ['app']);

    expect(Sanctum::personalAccessTokenModel())->toBe(PersonalAccessToken::class)
        ->and($token->accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and(PersonalAccessToken::findToken($token->plainTextToken)?->is($token->accessToken))->toBeTrue();
});

it('a real token still signs in, and the largest id a bigint holds is only looked up', function () {
    [$user] = makeAccount();
    $token = $user->createToken('phone', ['app'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken('9223372036854775807|'.str_repeat('a', 40))->getJson('/api/v1/me')->assertUnauthorized();

    expect(ErrorIssue::query()->count())->toBe(0);
});
