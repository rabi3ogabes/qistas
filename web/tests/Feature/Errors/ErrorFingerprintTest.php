<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorFingerprint;
use App\Errors\ErrorScrubber;

/*
| The fingerprint decides which failures are ONE issue (design section 4): sha1(source | kind | class | normalised message |
| top frame). Too loose and two different bugs share an issue; too tight and one bug becomes a hundred issues. The cases
| below pin both sides. Everything here is pure: no database, no request.
|
| The recorder scrubs an event first and fingerprints the scrubbed one, so the input normally carries the scrubber's masks
| ([id], [phone], [email], [token], [secret], ?). The tests feed the normaliser both that and raw text, because a raw
| uuid or digit run must never split an issue if it ever reaches it.
*/

/** A full event with any field replaced (own name: test helpers are global across files). */
function errorsFingerprintEvent(array $with = []): ErrorEvent
{
    return new ErrorEvent(...($with + [
        'source' => 'site',
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'message' => 'Something broke',
        'trace' => "#0 app/Foo.php(12): Foo->bar()\n#1 {main}",
        'status' => 500,
        'method' => 'GET',
        'route' => '/app/customers/{customer}',
        'version' => '1.30.0',
        'platform' => 'server',
        'device' => 'desktop',
        'userId' => '9d2f4c7e-0b1a-4c55-8f3e-1a2b3c4d5e6f',
        'at' => '2026-11-04T10:30:00+00:00',
    ]));
}

/** An error page the way a recorder builds it: no class, the status, the route (or the path) as `route`. */
function errorsFingerprintPage(string $route, int $status = 404, array $with = []): ErrorEvent
{
    return errorsFingerprintEvent($with + [
        'kind' => 'http',
        'class' => '',
        'message' => '',
        'trace' => '',
        'status' => $status,
        'route' => $route,
    ]);
}

/** A slow query: the SQL is the message. */
function errorsFingerprintSlowQuery(string $sql): ErrorEvent
{
    return errorsFingerprintEvent([
        'source' => 'database',
        'kind' => 'slow_query',
        'class' => '',
        'message' => $sql,
        'trace' => '',
        'status' => null,
        'route' => null,
    ]);
}

/**
 * The scrubbed trace of an exception thrown directly inside a controller method: the framework calls the method, so the
 * file of the frame is vendor and OUR code is only in the function column. The innermost frame in a file under app/ is a
 * middleware that every request passes through.
 */
function errorsFingerprintControllerTrace(string $controller, string $method = 'store', int $line = 46): string
{
    return implode("\n", [
        "#0 vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php({$line}): App\\Http\\Controllers\\{$controller}->{$method}()",
        '#1 vendor/laravel/framework/src/Illuminate/Routing/Route.php(265): Illuminate\\Routing\\ControllerDispatcher->dispatch()',
        '#2 vendor/laravel/framework/src/Illuminate/Routing/Route.php(211): Illuminate\\Routing\\Route->runController()',
        '#3 vendor/laravel/framework/src/Illuminate/Routing/Router.php(822): Illuminate\\Routing\\Route->run()',
        '#4 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(180): Illuminate\\Routing\\Router->Illuminate\\Routing\\{closure}()',
        '#5 app/Http/Middleware/ReadOnlyWhileDeleting.php(29): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}()',
        '#6 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(180): App\\Http\\Middleware\\ReadOnlyWhileDeleting->handle()',
        '#7 {main}',
    ]);
}

/** The scrubbed trace of a queued job (or a command, or a listener) whose handle() throws: the same shape, from the container. */
function errorsFingerprintJobTrace(string $class, int $line = 36): string
{
    return implode("\n", [
        "#0 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php({$line}): {$class}->handle()",
        '#1 vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call()',
        '#2 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(96): Illuminate\\Container\\Util::unwrapIfClosure()',
        '#3 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()',
        '#4 vendor/laravel/framework/src/Illuminate/Container/Container.php(803): Illuminate\\Container\\BoundMethod::call()',
        '#5 vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(136): Illuminate\\Container\\Container->call()',
        '#6 {main}',
    ]);
}

// ---- normalise ------------------------------------------------------------------------------------------------------

dataset('errors fingerprint messages', [
    // ids
    'a uuid' => [
        'No query results for model [App\Models\Customer] 550e8400-e29b-41d4-a716-446655440000',
        'No query results for model [App\Models\Customer] <id>',
    ],
    'a uuid in capitals' => ['Contract 550E8400-E29B-41D4-A716-446655440000 is closed', 'Contract <id> is closed'],
    'a uuid glued to a word' => ['user_550e8400-e29b-41d4-a716-446655440000 missing', 'user_<id> missing'],

    // numbers
    'numbers' => ['Order 12 of 3400 failed', 'Order <n> of <n> failed'],
    'a number in Arabic-Indic digits' => ['الطلب ١٢٣ فشل', 'الطلب <n> فشل'],
    'a version and a date' => ['Needs 1.30.0 since 2026-11-04', 'Needs <n>.<n>.<n> since <n>-<n>-<n>'],
    'a line number' => ['Undefined variable $x in app/Foo.php on line 12', 'Undefined variable $x in app/Foo.php on line <n>'],
    'a number longer than sixteen digits' => ['Account 1234567890123456 not found', 'Account <n> not found'],
    'the SQLSTATE code is kept, it says what kind of failure it was' => [
        'SQLSTATE[23505]: Unique violation: 7 duplicate key',
        'SQLSTATE[23505]: Unique violation: <n> duplicate key',
    ],
    'a lettered SQLSTATE code is kept too' => [
        'SQLSTATE[HY000]: General error: 1 no such table',
        'SQLSTATE[HY000]: General error: <n> no such table',
    ],

    // hex
    'a 40 character hex string' => ['Hash da39a3ee5e6b4b0d3255bfef95601890afd80709 mismatch', 'Hash <hex> mismatch'],
    'a 16 character hex string' => ['Key abcdefabcdefabcd rejected', 'Key <hex> rejected'],
    'a 15 character hex string is only a word' => ['Key abcdefabcdefabc rejected', 'Key abcdefabcdefabc rejected'],
    'a hex string written 0x' => ['Pointer 0xdeadbeefdeadbeef bad', 'Pointer <hex> bad'],

    // quoted strings
    'a single quoted name' => ["Customer 'Ahmed Ali' not found", 'Customer ? not found'],
    'a double quoted key' => ['Undefined array key "name"', 'Undefined array key ?'],
    'a quote written twice inside a string' => ["Value 'O''Brien' rejected", 'Value ? rejected'],
    'an apostrophe in a word' => ["Can't find the user's record", "Can't find the user's record"],
    'an apostrophe inside a quoted name' => ["Customer 'Ahmed's' not found", 'Customer ? not found'],
    'a quoted string with digits in it' => ["Code 'AB-12' unknown", 'Code ? unknown'],
    'a quoted mask' => ["Mail '[email]' bounced", 'Mail ? bounced'],

    // SQL: a literal goes, the structure stays
    'a quoted literal and numbers in a select' => [
        'select * from "contracts" where "customer_name" = \'Ahmed Ali\' and "tenant_id" = 7 limit 25',
        'select * from "contracts" where "customer_name" = ? and "tenant_id" = <n> limit <n>',
    ],
    'a select the scrubber already cleaned' => [
        'select * from "contracts" where "customer_name" = ? and "tenant_id" = ? limit ?',
        'select * from "contracts" where "customer_name" = ? and "tenant_id" = ? limit ?',
    ],
    'a list of marks is one mark' => [
        'select * from "customers" where "id" in (?, ?, ?)',
        'select * from "customers" where "id" in (?)',
    ],
    'a list of numbers is one number' => [
        'select * from "customers" where "id" in (4, 5, 6) and "x" = 9',
        'select * from "customers" where "id" in (<n>) and "x" = <n>',
    ],
    'a bulk insert of three rows is one row' => [
        'insert into "payments" ("a", "ref") values (?, ?), (?, ?), (?, ?)',
        'insert into "payments" ("a", "ref") values (?)',
    ],
    'a bulk insert of one row is the same row' => [
        'insert into "payments" ("a", "ref") values (?, ?)',
        'insert into "payments" ("a", "ref") values (?)',
    ],
    'a bulk insert of single values' => [
        'insert into "tags" ("name") values (?), (?), (?), (?)',
        'insert into "tags" ("name") values (?)',
    ],
    'a bulk insert of numbers' => [
        'insert into "t" ("a", "b") values (1, 2), (3, 4)',
        'insert into "t" ("a", "b") values (<n>)',
    ],

    // masks and whitespace
    'the scrubber masks are left alone' => [
        'Mail to [email] or [phone] about [id] with [token] [secret] [ip] [host] [path] at ? ok',
        'Mail to [email] or [phone] about [id] with [token] [secret] [ip] [host] [path] at ? ok',
    ],
    'whitespace is collapsed' => ["A   broken \n\n thing\t here ", 'A broken thing here'],
    'nothing' => ['', ''],
]);

it('normalises a message', function (string $message, string $expected) {
    expect(ErrorFingerprint::normalise($message))->toBe($expected);
})->with('errors fingerprint messages');

it('normalises a normalised message to itself', function (string $message, string $expected) {
    expect(ErrorFingerprint::normalise($expected))->toBe($expected);
})->with('errors fingerprint messages');

it('cuts a message at 200 characters', function () {
    $long = str_repeat('word ', 100);

    expect(mb_strlen(ErrorFingerprint::normalise($long)))->toBeLessThanOrEqual(200)
        ->and(ErrorFingerprint::normalise($long))->toStartWith('word word word')
        ->and(ErrorFingerprint::normalise(str_repeat('é', 300)))->toBe(str_repeat('é', 200))
        ->and(ErrorFingerprint::normalise($long, 20))->toBe('word word word word')
        ->and(mb_check_encoding(ErrorFingerprint::normalise(str_repeat('خطأ ', 200)), 'UTF-8'))->toBeTrue();
});

it('never leaves the front half of a mask or a placeholder when it cuts', function () {
    $mask = ErrorFingerprint::normalise(str_repeat('x', 195).' [email] tail');
    $placeholder = ErrorFingerprint::normalise(str_repeat('x', 197).' 550e8400-e29b-41d4-a716-446655440000');

    expect($mask)->toBe(str_repeat('x', 195))
        ->and($placeholder)->toBe(str_repeat('x', 197))
        ->and(ErrorFingerprint::normalise(str_repeat('x', 190).' 550e8400-e29b-41d4-a716-446655440000'))
        ->toBe(str_repeat('x', 190).' <id>');
});

it('survives a text that is not valid UTF-8', function () {
    $bad = "Order 12 \xC3\x28 failed";

    expect(mb_check_encoding(ErrorFingerprint::normalise($bad), 'UTF-8'))->toBeTrue()
        ->and(ErrorFingerprint::normalise($bad))->toContain('Order <n>');
});

it('keeps the names of tables apart in a statement, so two queries are two issues', function () {
    $contracts = ErrorFingerprint::normalise('select * from "contracts" where "tenant_id" = ?');
    $customers = ErrorFingerprint::normalise('select * from "customers" where "tenant_id" = ?');

    expect($contracts)->not->toBe($customers)
        ->and($contracts)->toContain('"contracts"');
});

// ---- of -------------------------------------------------------------------------------------------------------------

it('is the sha1 of source, kind, class, normalised message and top frame', function () {
    expect(ErrorFingerprint::of(errorsFingerprintEvent()))
        ->toBe(sha1('site|exception|RuntimeException|Something broke|app/Foo.php::Foo->bar'))
        ->toMatch('/^[0-9a-f]{40}$/');
});

it('groups two messages that differ only in ids or numbers', function () {
    $a = errorsFingerprintEvent(['message' => 'No contract 12 for customer 550e8400-e29b-41d4-a716-446655440000 (hash da39a3ee5e6b4b0d3255bfef95601890afd80709)']);
    $b = errorsFingerprintEvent(['message' => 'No contract 9871 for customer 9d2f4c7e-0b1a-4c55-8f3e-1a2b3c4d5e6f (hash 0123456789abcdef0123456789abcdef)']);

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('groups two messages that differ only in a quoted string', function () {
    $a = errorsFingerprintEvent(['message' => "Customer 'Ahmed Ali' not found"]);
    $b = errorsFingerprintEvent(['message' => "Customer 'Sara Hassan' not found"]);

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('groups the same failure after the scrubber has masked its values', function () {
    $a = ErrorScrubber::event(errorsFingerprintEvent(['message' => 'Could not text 0501234567 about contract 550e8400-e29b-41d4-a716-446655440000']));
    $b = ErrorScrubber::event(errorsFingerprintEvent(['message' => 'Could not text 0559876543 about contract 9d2f4c7e-0b1a-4c55-8f3e-1a2b3c4d5e6f']));

    expect($a->message)->toBe('Could not text [phone] about contract [id]')
        ->and(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('keeps different failures apart', function (array $other) {
    expect(ErrorFingerprint::of(errorsFingerprintEvent($other)))->not->toBe(ErrorFingerprint::of(errorsFingerprintEvent()));
})->with([
    'another class' => [['class' => 'LogicException']],
    'another top frame' => [['trace' => "#0 app/Bar.php(12): Bar->baz()\n#1 {main}"]],
    'another function in the same file' => [['trace' => "#0 app/Foo.php(12): Foo->other()\n#1 {main}"]],
    'another message' => [['message' => 'Something else broke']],
    'another source' => [['source' => 'api']],
    'another kind' => [['kind' => 'script']],
    'no frame of ours' => [['trace' => "#0 vendor/x/Y.php(1): Y->z()\n#1 {main}"]],
]);

it('does not let anything that changes between sightings split an issue', function (array $other) {
    expect(ErrorFingerprint::of(errorsFingerprintEvent($other)))->toBe(ErrorFingerprint::of(errorsFingerprintEvent()));
})->with([
    'the version' => [['version' => '1.31.0']],
    'the time' => [['at' => '2026-12-24T08:00:00+00:00']],
    'the person' => [['userId' => '11111111-2222-4333-8444-555555555555']],
    'the device' => [['device' => 'mobile']],
    'the method' => [['method' => 'POST']],
    'the route of an exception' => [['route' => '/app/contracts/{contract}']],
    'the status of an exception' => [['status' => 503]],
]);

it('is stable across a deploy: the same exception at another line is the same issue', function () {
    $before = errorsFingerprintEvent([
        'trace' => implode("\n", [
            '#0 vendor/laravel/framework/src/Illuminate/Database/Connection.php(826): PDOStatement->execute()',
            '#1 app/Actions/RecordPayment.php(48): Illuminate\\Database\\Connection->select()',
            '#2 app/Http/Controllers/PaymentController.php(30): App\\Actions\\RecordPayment->handle()',
            '#3 {main}',
        ]),
    ]);
    $after = errorsFingerprintEvent([
        'trace' => implode("\n", [
            '#0 vendor/laravel/framework/src/Illuminate/Database/Connection.php(911): PDOStatement->execute()',
            '#1 app/Actions/RecordPayment.php(61): Illuminate\\Database\\Connection->select()',
            '#2 app/Http/Controllers/PaymentController.php(44): App\\Actions\\RecordPayment->handle()',
            '#3 {main}',
        ]),
    ]);

    expect(ErrorFingerprint::of($before))->toBe(ErrorFingerprint::of($after));
});

it('is stable across a deploy even when the top frame is a closure, which PHP 8.4 names with its line', function () {
    $before = errorsFingerprintEvent(['trace' => "#0 app/Foo.php(4): App\\Foo->{closure:{closure:App\\Foo::bar():4}:4}()\n#1 {main}"]);
    $after = errorsFingerprintEvent(['trace' => "#0 app/Foo.php(9): App\\Foo->{closure:{closure:App\\Foo::bar():9}:9}()\n#1 {main}"]);

    expect(ErrorFingerprint::of($before))->toBe(ErrorFingerprint::of($after))
        ->and(ErrorFingerprint::topFrame($before->trace, 'server'))->toBe('app/Foo.php::App\\Foo->{closure}');
});

it('is stable across a deploy for a closure of the top level, after the scrubber has cut the paths', function () {
    $root = str_replace('\\', '/', base_path());
    $scrubbed = fn (int $line) => ErrorScrubber::event(errorsFingerprintEvent([
        'trace' => "#0 {$root}/app/Foo.php({$line}): App\\Foo->{closure:{$root}/app/Foo.php:{$line}}()\n#1 {main}",
    ]));

    expect($scrubbed(12)->trace)->toBe("#0 app/Foo.php(12): App\\Foo->{closure:app/Foo.php:12}()\n#1 {main}")
        ->and(ErrorFingerprint::topFrame($scrubbed(12)->trace, 'server'))->toBe('app/Foo.php::App\\Foo->{closure}')
        ->and(ErrorFingerprint::of($scrubbed(12)))->toBe(ErrorFingerprint::of($scrubbed(99)));
});

it('is stable across a build: a hash with a hyphen in it is still a hash', function () {
    $script = fn (string $file) => errorsFingerprintEvent([
        'source' => 'site', 'kind' => 'script', 'class' => 'TypeError', 'message' => 'x is undefined', 'platform' => 'web',
        'trace' => "TypeError: x is undefined\n    at render (https://qistas.app/build/assets/{$file}:1:2345)",
    ]);

    expect(ErrorFingerprint::of($script('app-D-yac90l.js')))->toBe(ErrorFingerprint::of($script('app-k5V7fBI0.js')))
        ->and(ErrorFingerprint::of($script('app--yKEDEoH.js')))->toBe(ErrorFingerprint::of($script('app-k5V7fBI0.js')));
});

it('is stable across the number of rows of a bulk insert', function () {
    $insert = fn (string $rows) => ErrorScrubber::event(errorsFingerprintEvent([
        'source' => 'database',
        'class' => 'Illuminate\\Database\\QueryException',
        'message' => 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: payments.ref (Connection: sqlite, SQL: insert into "payments" ("a", "ref") values '.$rows.')',
    ]));

    expect(ErrorFingerprint::of($insert('(1, X1)')))->toBe(ErrorFingerprint::of($insert('(1, X1), (2, X2), (3, X3)')));
});

it('does not let the line of an anonymous class split an issue', function () {
    $a = errorsFingerprintEvent(['class' => 'Illuminate\\Foo@anonymousapp/Support/Thing.php:12$0']);
    $b = errorsFingerprintEvent(['class' => 'Illuminate\\Foo@anonymousapp/Support/Thing.php:48$0']);

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('groups an error page by its route pattern, not by the address that was asked for', function () {
    $a = errorsFingerprintPage('/app/customers/550e8400-e29b-41d4-a716-446655440000');
    $b = errorsFingerprintPage('/app/customers/9d2f4c7e-0b1a-4c55-8f3e-1a2b3c4d5e6f');

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b))
        ->and(ErrorFingerprint::of($a))->toBe(sha1('site|http|http.404|/app/customers/{id}|-'));
});

it('groups an error page by its route pattern after the scrubber, with numbers and uuids', function () {
    $a = ErrorScrubber::event(errorsFingerprintPage('/app/contracts/12/edit?tab=notes'));
    $b = ErrorScrubber::event(errorsFingerprintPage('app/contracts/9871/edit'));
    $c = ErrorScrubber::event(errorsFingerprintPage('/app/contracts/550e8400-e29b-41d4-a716-446655440000/edit'));

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b))
        ->and(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($c));
});

it('groups a matched route written with and without its leading slash', function () {
    expect(ErrorFingerprint::of(errorsFingerprintPage('app/customers/{customer}')))
        ->toBe(ErrorFingerprint::of(errorsFingerprintPage('/app/customers/{customer}')));
});

it('keeps error pages with another status or another route apart', function () {
    $base = ErrorFingerprint::of(errorsFingerprintPage('/app/customers/{customer}'));

    expect(ErrorFingerprint::of(errorsFingerprintPage('/app/customers/{customer}', 403)))->not->toBe($base)
        ->and(ErrorFingerprint::of(errorsFingerprintPage('/app/contracts/{contract}')))->not->toBe($base)
        ->and(ErrorFingerprint::of(errorsFingerprintPage('/app/customers/{customer}', 404, ['source' => 'api'])))->not->toBe($base);
});

it('takes the route pattern of an error page, whatever message it carries', function () {
    $a = errorsFingerprintPage('/pricing-typo', 404, ['message' => 'one wording']);
    $b = errorsFingerprintPage('/pricing-typo', 404, ['message' => 'another wording']);

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('falls back to the message for an error page with no route', function () {
    $a = errorsFingerprintEvent(['kind' => 'http', 'class' => '', 'status' => 404, 'route' => null, 'message' => '/old-link/12?ref=mail', 'trace' => '']);

    expect(ErrorFingerprint::of($a))->toBe(sha1('site|http|http.404|/old-link/{n}|-'));
});

it('ignores the literals of a slow query', function () {
    $a = errorsFingerprintSlowQuery('select * from "contracts" where "customer_name" = \'Ahmed Ali\' and "tenant_id" = 7 limit 25');
    $b = errorsFingerprintSlowQuery('select * from "contracts" where "customer_name" = \'Sara Hassan\' and "tenant_id" = 9001 limit 50');

    expect(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('ignores the bindings of a slow query after the scrubber, and the length of an in list', function () {
    $a = ErrorScrubber::event(errorsFingerprintSlowQuery('select * from "customers" where "name" = Ahmed Ali and "id" in (4, 5) order by "id" desc'));
    $b = ErrorScrubber::event(errorsFingerprintSlowQuery('select * from "customers" where "name" = Sara Hassan and "id" in (7, 8, 9) order by "id" desc'));

    expect($a->message)->toBe('select * from "customers" where "name" = ? and "id" in (?, ?) order by "id" desc')
        ->and(ErrorFingerprint::of($a))->toBe(ErrorFingerprint::of($b));
});

it('keeps slow queries on other tables or with other clauses apart', function () {
    $base = ErrorFingerprint::of(errorsFingerprintSlowQuery('select * from "contracts" where "tenant_id" = ?'));

    expect(ErrorFingerprint::of(errorsFingerprintSlowQuery('select * from "customers" where "tenant_id" = ?')))->not->toBe($base)
        ->and(ErrorFingerprint::of(errorsFingerprintSlowQuery('select * from "contracts" where "status" = ?')))->not->toBe($base);
});

it('cuts a slow query at 300 characters and any other message at 200', function () {
    $sharedFor = static fn (int $length): string => str_repeat('x', $length);

    // They differ at character 251: the 200 cut of a plain message cannot see it, the 300 cut of a query can.
    expect(ErrorFingerprint::of(errorsFingerprintEvent(['message' => $sharedFor(250).'A'])))
        ->toBe(ErrorFingerprint::of(errorsFingerprintEvent(['message' => $sharedFor(250).'B'])))
        ->and(ErrorFingerprint::of(errorsFingerprintSlowQuery($sharedFor(250).'A')))
        ->not->toBe(ErrorFingerprint::of(errorsFingerprintSlowQuery($sharedFor(250).'B')))
        // ... and at character 311 neither can.
        ->and(ErrorFingerprint::of(errorsFingerprintSlowQuery($sharedFor(310).'A')))
        ->toBe(ErrorFingerprint::of(errorsFingerprintSlowQuery($sharedFor(310).'B')));
});

it('fingerprints a script error and a phone crash by their own frames', function () {
    $script = fn (string $hash) => errorsFingerprintEvent([
        'source' => 'site', 'kind' => 'script', 'class' => 'TypeError', 'message' => 'x is undefined', 'platform' => 'web',
        'trace' => "TypeError: x is undefined\n    at render (https://qistas.app/build/assets/app-{$hash}.js:1:2345)",
    ]);
    $crash = fn (int $line) => errorsFingerprintEvent([
        'source' => 'app', 'kind' => 'crash', 'class' => 'FormatException', 'message' => 'Unexpected character', 'platform' => 'android',
        'trace' => "#0      Foo.bar (package:qistas/core/foo.dart:{$line}:3)\n#1      _rootRunUnary (dart:async/zone.dart:1407:47)",
    ]);

    // A new build has a new hash in its file name; a new release moves the lines. Neither is a new issue.
    expect(ErrorFingerprint::of($script('Bx9a2c')))->toBe(ErrorFingerprint::of($script('Qk81zz')))
        ->and(ErrorFingerprint::of($crash(12)))->toBe(ErrorFingerprint::of($crash(99)))
        ->and(ErrorFingerprint::of($script('Bx9a2c')))->not->toBe(ErrorFingerprint::of($crash(12)));
});

it('reads at most 8192 bytes of a message, so a report of a megabyte costs what one of 8 KB costs', function () {
    // A megabyte of blanks and then a word: the 8192 bytes that are read are all blanks, so nothing is left. Read whole, the
    // word would be the message.
    $started = hrtime(true);
    $fingerprint = ErrorFingerprint::of(errorsFingerprintEvent(['message' => str_repeat(' ', 1024 * 1024).'tail']));
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($fingerprint)->toBe(sha1('site|exception|RuntimeException||app/Foo.php::Foo->bar'))
        ->and($seconds)->toBeLessThan(0.5)
        ->and(ErrorFingerprint::normalise(str_repeat(' ', 9000).'tail'))->toBe('')
        ->and(ErrorFingerprint::normalise(str_repeat(' ', 8000).'tail'))->toBe('tail');
});

it('reads at most the first 8192 bytes of a trace, however many lines it has', function () {
    // 100 000 lines of vendor frames and, a megabyte further on, a frame of ours. The scrubber keeps 8 KB of a trace, so
    // nothing real is longer; a trace that is reads no further than that.
    $trace = implode("\n", array_fill(0, 100000, '#0 vendor/x/Y.php(1): Y->z()'))."\n#1 app/Foo.php(1): Foo->bar()";

    $started = hrtime(true);
    $fingerprint = ErrorFingerprint::of(errorsFingerprintEvent(['trace' => $trace]));
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($fingerprint)->toBe(sha1('site|exception|RuntimeException|Something broke|-'))
        ->and($seconds)->toBeLessThan(0.5)
        ->and(ErrorFingerprint::topFrame("#0 vendor/x/Y.php(1): Y->z()\n#1 app/Foo.php(1): Foo->bar()", 'server'))->toBe('app/Foo.php::Foo->bar');
});

// ---- topFrame -------------------------------------------------------------------------------------------------------

dataset('errors fingerprint frames', [
    // PHP: the first frame under app/, file and function, no line
    'the first frame of our own code' => [
        implode("\n", [
            '#0 vendor/laravel/framework/src/Illuminate/Database/Connection.php(826): PDOStatement->execute()',
            '#1 app/Actions/RecordPayment.php(48): Illuminate\\Database\\Connection->select()',
            '#2 app/Http/Controllers/PaymentController.php(30): App\\Actions\\RecordPayment->handle()',
            '#3 {main}',
        ]),
        'server',
        'app/Actions/RecordPayment.php::Illuminate\\Database\\Connection->select',
    ],
    'a frame whose arguments were not scrubbed' => [
        "#0 app/Foo.php(12): Foo->bar('Ahmed Ali', Array)",
        'server',
        'app/Foo.php::Foo->bar',
    ],
    'a static call' => ['#0 app/Foo.php(12): App\\Foo::make()', 'server', 'app/Foo.php::App\\Foo::make'],
    'a plain function' => ['#0 app/helpers.php(3): fmt()', 'server', 'app/helpers.php::fmt'],
    'a closure named the PHP 8.4 way' => [
        '#0 app/Foo.php(4): App\\Foo->{closure:{closure:App\\Foo::bar():4}:4}()',
        'server',
        'app/Foo.php::App\\Foo->{closure}',
    ],
    'a closure named the older way' => [
        '#0 app/Foo.php(4): App\\Foo->App\\{closure}()',
        'server',
        'app/Foo.php::App\\Foo->App\\{closure}',
    ],
    'a closure of the top level, the shape that reaches the fold' => [
        '#0 app/Foo.php(12): App\\Foo->{closure:app/Foo.php:12}()',
        'server',
        'app/Foo.php::App\\Foo->{closure}',
    ],

    // PHP: a frame whose file the scrubber masked (outside the project) is still ours when its function is ours
    'a masked file with our function' => [
        "#0 [path](52): App\\Actions\\Act->handle()\n#1 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Pipeline\\Pipeline->then()",
        'server',
        'App\\Actions\\Act->handle',
    ],
    'a masked file with a function that is not ours' => [
        "#0 [path](52): Vendor\\Thing->run()\n#1 app/Foo.php(12): App\\Foo->bar()",
        'server',
        'app/Foo.php::App\\Foo->bar',
    ],

    // PHP: our code in the function column, called by vendor code (a controller, a job, a command, a view)
    'a controller method called by the framework' => [
        errorsFingerprintControllerTrace('PaymentController'),
        'server',
        'App\\Http\\Controllers\\PaymentController->store',
    ],
    'a job whose handle() throws' => [
        errorsFingerprintJobTrace('App\\Jobs\\SendReminder'),
        'server',
        'App\\Jobs\\SendReminder->handle',
    ],
    'a command whose handle() throws' => [
        errorsFingerprintJobTrace('App\\Console\\Commands\\PruneThings'),
        'server',
        'App\\Console\\Commands\\PruneThings->handle',
    ],
    'a middleware that throws' => [
        '#0 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(180): App\\Http\\Middleware\\ReadOnlyWhileDeleting->handle()',
        'server',
        'App\\Http\\Middleware\\ReadOnlyWhileDeleting->handle',
    ],
    'a model method called from a compiled view' => [
        "#0 storage/framework/views/9f8e7d6c5b4a3210.php(12): App\\Models\\Customer->displayName()\n#1 vendor/laravel/framework/src/Illuminate/Filesystem/Filesystem.php(123): require()",
        'server',
        'App\\Models\\Customer->displayName',
    ],
    'a listener called from no file' => [
        "#0 [internal function]: App\\Listeners\\SendWelcome->handle()\n#1 vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php(458): call_user_func_array()",
        'server',
        'App\\Listeners\\SendWelcome->handle',
    ],
    'a static call of ours from vendor code' => [
        '#0 vendor/laravel/framework/src/Illuminate/Support/Collection.php(10): App\\Support\\Money::sum()',
        'server',
        'App\\Support\\Money::sum',
    ],
    'the first frame of ours wins, in a file under app/' => [
        "#0 app/Actions/Y.php(10): App\\Actions\\Z->run()\n#1 vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(46): App\\Http\\Controllers\\X->store()",
        'server',
        'app/Actions/Y.php::App\\Actions\\Z->run',
    ],
    'the first frame of ours wins, in the function column' => [
        "#0 vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(46): App\\Http\\Controllers\\X->store()\n#1 app/Http/Middleware/Y.php(29): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}()",
        'server',
        'App\\Http\\Controllers\\X->store',
    ],
    'a function that only starts like App is not ours' => [
        "#0 vendor/x/Y.php(1): Appendix\\Foo->bar()\n#1 vendor/x/Y.php(2): Application\\Foo->bar()\n#2 vendor/x/Y.php(3): app_helper()",
        'server',
        '-',
    ],
    'a trace with no frame of ours' => ["#0 vendor/x/Y.php(1): Y->z()\n#1 [internal function]: A->b()\n#2 {main}", 'server', '-'],
    'a folder that only starts like ours' => ["#0 appendix/Foo.php(1): Foo->bar()\n#1 vendor/app/Foo.php(2): Foo->baz()", 'server', '-'],
    'an empty trace' => ['', 'server', '-'],
    'only the main frame' => ['#0 {main}', 'server', '-'],

    // JavaScript: the file without its hash, and the function
    'a V8 frame' => [
        "TypeError: x is undefined\n    at render (https://qistas.app/build/assets/app-Bx9a2c.js:1:2345)",
        'web',
        'app.js::render',
    ],
    'a V8 frame with no function' => ['    at https://qistas.app/build/assets/vendor-Qk81zz.js:7:99', 'web', 'vendor.js::(anonymous)'],
    'a V8 frame of a method' => ['    at Object.render (https://qistas.app/build/assets/app-Bx9a2c.js:1:2)', 'web', 'app.js::render'],
    'a V8 async frame' => ['    at async submit (https://qistas.app/build/assets/app-Bx9a2c.js:5:6)', 'web', 'app.js::submit'],
    'a Firefox frame' => ['render@https://qistas.app/build/assets/app-Bx9a2c.js:12:3', 'web', 'app.js::render'],
    'a Firefox frame with no function' => ['@https://qistas.app/build/assets/app-Bx9a2c.js:12:3', 'web', 'app.js::(anonymous)'],
    'a frame with a query string before the line' => [
        '    at render (https://qistas.app/build/assets/app-Bx9a2c.js?v=3&user=ahmed:1:2345)',
        'web',
        'app.js::render',
    ],
    'an older hash written with a dot' => ['    at go (https://qistas.app/js/app.3f2a9c1b.js:5:6)', 'web', 'app.js::go'],
    'a hash after a hyphenated name' => ['    at go (https://qistas.app/build/assets/pay-day-Bx9a2c.js:5:6)', 'web', 'pay-day.js::go'],
    'a hyphenated name that is not a hash' => ['    at send (https://qistas.app/build/assets/error-report.js:3:4)', 'web', 'error-report.js::send'],
    'a hash with a hyphen in its second character' => ['    at go (https://qistas.app/build/assets/app-s-F4Y31w.js:5:6)', 'web', 'app.js::go'],
    'a hash that starts with a hyphen' => ['    at go (https://qistas.app/build/assets/app--yKEDEoH.js:5:6)', 'web', 'app.js::go'],
    'a hash with a hyphen after a hyphenated name' => ['    at go (https://qistas.app/build/assets/pay-day-D-yac90l.js:5:6)', 'web', 'pay-day.js::go'],
    'a word of eight letters is not a hash' => ['    at go (https://qistas.app/build/assets/app-handlers.js:5:6)', 'web', 'app-handlers.js::go'],
    'a frame of a Chrome extension is passed over' => [
        "TypeError: x is undefined\n    at inject (chrome-extension://abcdefghijklmnopabcdefghijklmnop/content.js:1:2)\n    at render (https://qistas.app/build/assets/app-Bx9a2c.js:1:2)",
        'web',
        'app.js::render',
    ],
    'a frame of a Firefox extension is passed over' => [
        "inject@moz-extension://1b2c3d4e-0000-4000-8000-123456789abc/content.js:1:2\nrender@https://qistas.app/build/assets/app-Bx9a2c.js:1:2",
        'web',
        'app.js::render',
    ],
    'a frame of a Safari extension is passed over' => [
        "inject@safari-web-extension://ABCD-1234/content.js:1:2\nrender@https://qistas.app/build/assets/app-Bx9a2c.js:1:2",
        'web',
        'app.js::render',
    ],
    'a script trace with only an extension frame' => ['    at inject (chrome-extension://abcdefghijklmnop/content.js:1:2)', 'web', '-'],
    'frames that are not scripts are passed over' => [
        implode("\n", [
            'TypeError: x is undefined',
            '    at new Promise (<anonymous>)',
            '    at Array.map (<anonymous>)',
            '    at <anonymous>',
            '    at go (https://qistas.app/build/assets/app-Bx9a2c.js:5:6)',
        ]),
        'web',
        'app.js::go',
    ],
    'a script trace with no script frame' => ["TypeError: x\n    at <anonymous>", 'web', '-'],

    // Dart: the path under qistas, and the function
    'a Dart frame' => [
        "#0      Foo.bar (package:qistas/core/foo.dart:12:3)\n#1      _rootRunUnary (dart:async/zone.dart:1407:47)",
        'android',
        'core/foo.dart::Foo.bar',
    ],
    'a Dart frame on iOS, after the framework' => [
        implode("\n", [
            '#0      State.setState (package:flutter/src/widgets/framework.dart:1:2)',
            '#1      _HomeState.build.<anonymous closure> (package:qistas/ui/home.dart:50:7)',
            '<asynchronous suspension>',
        ]),
        'ios',
        'ui/home.dart::_HomeState.build.<anonymous closure>',
    ],
    'a Dart trace with no frame of ours' => ["#0      _rootRunUnary (dart:async/zone.dart:1407:47)\n<asynchronous suspension>", 'android', '-'],

    // a trace of one platform is not read as another
    'a PHP trace on the web' => ['#0 app/Foo.php(12): Foo->bar()', 'web', '-'],
    'a Dart trace on the server' => ['#0      Foo.bar (package:qistas/core/foo.dart:12:3)', 'server', '-'],
    'an unknown platform' => ['#0 app/Foo.php(12): Foo->bar()', 'toaster', '-'],
    'no platform' => ['#0 app/Foo.php(12): Foo->bar()', '', '-'],
]);

it('finds the top frame of our own code', function (string $trace, string $platform, string $expected) {
    expect(ErrorFingerprint::topFrame($trace, $platform))->toBe($expected);
})->with('errors fingerprint frames');

it('keeps two controllers that throw the same message apart, and one controller at two lines together', function () {
    $message = 'Call to a member function format() on null';
    $payments = errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintControllerTrace('PaymentController')]);
    $contracts = errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintControllerTrace('ContractController')]);
    $other = errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintControllerTrace('PaymentController', 'update')]);
    $later = errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintControllerTrace('PaymentController', 'store', 48)]);

    expect(ErrorFingerprint::of($payments))->not->toBe(ErrorFingerprint::of($contracts))
        ->and(ErrorFingerprint::of($payments))->not->toBe(ErrorFingerprint::of($other))
        ->and(ErrorFingerprint::of($payments))->toBe(ErrorFingerprint::of($later));
});

it('keeps two jobs, two commands and a job and a command that throw the same message apart', function () {
    $message = 'Call to a member function format() on null';
    $fingerprints = array_map(
        fn (string $class): string => ErrorFingerprint::of(errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintJobTrace($class)])),
        ['App\\Jobs\\SendReminder', 'App\\Jobs\\SendReceipt', 'App\\Console\\Commands\\PruneThings'],
    );
    $later = ErrorFingerprint::of(errorsFingerprintEvent(['message' => $message, 'trace' => errorsFingerprintJobTrace('App\\Jobs\\SendReminder', 41)]));

    expect(array_unique($fingerprints))->toHaveCount(3)
        ->and($later)->toBe($fingerprints[0]);
});

it('cuts a top frame that is absurdly long', function () {
    $frame = ErrorFingerprint::topFrame('#0 app/Foo.php(1): '.str_repeat('A', 5000).'->bar()', 'server');

    expect(mb_strlen($frame))->toBeLessThanOrEqual(200)
        ->and($frame)->toStartWith('app/Foo.php::AAAA');
});

// ---- title ----------------------------------------------------------------------------------------------------------

it('writes the title in plain words: the class and the normalised message', function () {
    $event = errorsFingerprintEvent([
        'class' => 'Illuminate\\Database\\QueryException',
        'message' => 'No customer 42 for 550e8400-e29b-41d4-a716-446655440000',
    ]);

    expect(ErrorFingerprint::title($event))->toBe('QueryException: No customer <n> for <id>')
        ->and(ErrorFingerprint::title(errorsFingerprintEvent()))->toBe('RuntimeException: Something broke');
});

it('puts a title on one line', function () {
    $title = ErrorFingerprint::title(errorsFingerprintEvent([
        'message' => "First line\nsecond line\n#0 app/Foo.php(12): Foo->bar()",
    ]));

    expect($title)->not->toContain("\n")
        ->and($title)->toStartWith('RuntimeException: First line second line');
});

it('writes the title of an error page from its status and its route pattern', function (int $status, string $route, string $expected) {
    expect(ErrorFingerprint::title(errorsFingerprintPage($route, $status)))->toBe($expected);
})->with([
    [403, '/app/contracts/{contract}', '403 Forbidden: /app/contracts/{contract}'],
    [404, '/app/customers/550e8400-e29b-41d4-a716-446655440000', '404 Not Found: /app/customers/{id}'],
    [419, '/app/payments', '419 Page Expired: /app/payments'],
    [429, '/login', '429 Too Many Requests: /login'],
    [500, '/app', '500 Server Error: /app'],
    [503, '/api/v1/payments', '503 Service Unavailable: /api/v1/payments'],
    [502, '/api/v1/payments', '502 Bad Gateway: /api/v1/payments'],
    [599, '/api/v1/payments', '599 Error: /api/v1/payments'],
]);

it('writes the title of an error page that has no status', function () {
    expect(ErrorFingerprint::title(errorsFingerprintEvent(['kind' => 'http', 'class' => '', 'status' => null, 'route' => '/x'])))
        ->toBe('HTTP error: /x');
});

it('writes the title of a slow query as its statement', function () {
    expect(ErrorFingerprint::title(errorsFingerprintSlowQuery('select * from "contracts" where "tenant_id" = ? limit 25')))
        ->toBe('select * from "contracts" where "tenant_id" = ? limit <n>');
});

it('writes a title when there is a class and no message, or a message and no class, or neither', function () {
    expect(ErrorFingerprint::title(errorsFingerprintEvent(['message' => ''])))->toBe('RuntimeException')
        ->and(ErrorFingerprint::title(errorsFingerprintEvent(['class' => '', 'message' => 'Boom 7'])))->toBe('Boom <n>')
        ->and(ErrorFingerprint::title(errorsFingerprintEvent(['kind' => 'crash', 'class' => '', 'message' => ''])))->toBe('Crash')
        ->and(ErrorFingerprint::title(errorsFingerprintEvent(['kind' => 'slow_query', 'class' => '', 'message' => ''])))->toBe('Slow query');
});

it('caps a title at 200 characters, on a character boundary', function () {
    $english = ErrorFingerprint::title(errorsFingerprintEvent([
        'class' => str_repeat('A', 160),
        'message' => str_repeat('word ', 100),
    ]));
    $arabic = ErrorFingerprint::title(errorsFingerprintEvent(['message' => str_repeat('خطأ في الاتصال ', 100)]));
    $page = ErrorFingerprint::title(errorsFingerprintPage('/'.str_repeat('segment/', 40)));

    expect(mb_strlen($english))->toBeLessThanOrEqual(200)
        ->and(mb_strlen($arabic))->toBeLessThanOrEqual(200)
        ->and(mb_strlen($page))->toBeLessThanOrEqual(200)
        ->and(mb_check_encoding($arabic, 'UTF-8'))->toBeTrue()
        ->and($arabic)->toStartWith('RuntimeException: خطأ في الاتصال');
});

it('does not cut a title in the middle of a placeholder', function () {
    $title = ErrorFingerprint::title(errorsFingerprintEvent([
        'class' => 'RuntimeException',
        'message' => str_repeat('x', 178).' 550e8400-e29b-41d4-a716-446655440000',
    ]));

    expect($title)->not->toEndWith('<')
        ->and($title)->not->toEndWith('<i')
        ->and($title)->not->toEndWith('<id');
});

// ---- routePattern ---------------------------------------------------------------------------------------------------

it('reduces a path to its pattern', function (string $path, string $expected) {
    expect(ErrorFingerprint::routePattern($path))->toBe($expected);
})->with([
    'a uuid' => ['/app/customers/550e8400-e29b-41d4-a716-446655440000', '/app/customers/{id}'],
    'a uuid in capitals' => ['/app/customers/550E8400-E29B-41D4-A716-446655440000', '/app/customers/{id}'],
    'a ulid' => ['/app/payments/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/app/payments/{id}'],
    'a hash' => ['/files/da39a3ee5e6b4b0d3255bfef95601890afd80709', '/files/{id}'],
    'a number' => ['/app/customers/12', '/app/customers/{n}'],
    'a number in the middle' => ['/app/customers/12/edit', '/app/customers/{n}/edit'],
    'several numbers' => ['/app/customers/12/contracts/9871', '/app/customers/{n}/contracts/{n}'],
    'a query string and a fragment' => ['/app/customers/12/edit?tab=notes&q=Ahmed#top', '/app/customers/{n}/edit'],
    'no leading slash' => ['app/customers/12', '/app/customers/{n}'],
    'several leading slashes' => ['//app/customers', '/app/customers'],
    'the root' => ['/', '/'],
    'nothing' => ['', '/'],
    'a parameter of a route' => ['/app/customers/{customer}', '/app/customers/{customer}'],
    'an optional parameter' => ['/app/customers/{customer?}', '/app/customers/{customer?}'],
    'an id already masked by the scrubber' => ['/app/customers/{id}/edit', '/app/customers/{id}/edit'],
    'a page of the site' => ['/pricing-typo', '/pricing-typo'],
    'a page of the site in Arabic' => ['/ar/pricing', '/ar/pricing'],
    'an api version is not a number' => ['/api/v1/contracts/12', '/api/v1/contracts/{n}'],
    'a trailing slash' => ['/app/customers/12/', '/app/customers/{n}/'],
    'an e-mail address in a path' => ['/unsubscribe/ahmed@example.com', '/unsubscribe/[email]'],
    'a percent encoded uuid' => ['/app/customers/550e8400%2De29b-41d4-a716-446655440000', '/app/customers/{id}'],
]);

it('reduces a pattern to itself', function () {
    $pattern = ErrorFingerprint::routePattern('/app/customers/12/contracts/550e8400-e29b-41d4-a716-446655440000?x=1');

    expect($pattern)->toBe('/app/customers/{n}/contracts/{id}')
        ->and(ErrorFingerprint::routePattern($pattern))->toBe($pattern);
});

it('keeps a very long path within the width of the route column', function () {
    expect(mb_strlen(ErrorFingerprint::routePattern('/'.str_repeat('segment/', 100))))->toBeLessThanOrEqual(ErrorScrubber::ROUTE_MAX);
});

it('reads a hostile path in no time: a path built to make the query pattern quadratic is cut before it is read', function () {
    // This is the test of routePattern's 8192-byte cut: without it the lookahead of the query pattern reads about 65000 x
    // 65000 / 2 characters. Only the time can tell: the scrubber cuts a route on its own as well, so what comes out is the
    // same with or without the cut (a test that compared the result was dropped for that reason).
    $started = hrtime(true);
    $pattern = ErrorFingerprint::routePattern('/'.str_repeat('?', 65000).'}');
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($seconds)->toBeLessThan(0.3)
        ->and(mb_strlen($pattern))->toBeLessThanOrEqual(ErrorScrubber::ROUTE_MAX);
});

// ---- isScannerPath --------------------------------------------------------------------------------------------------

it('knows the paths only a scanner asks for', function (string $path) {
    expect(ErrorFingerprint::isScannerPath($path))->toBeTrue();
})->with([
    '/.env',
    '/.env.production',
    '/.git/config',
    '/wp-login.php',
    '/wp-admin/setup-config.php',
    '/wp-content/plugins/x/readme.txt',
    '/xmlrpc.php',
    '/phpmyadmin/index.php',
    '/vendor/phpunit/x',
    '/admin.php',
    '/backup.sql',
    '/db.bak',
    '/x.zip',
    '/a/b/c/shell.php',
    'a path in capitals' => '/WP-LOGIN.PHP',
    'a dot env in capitals' => '/.ENV',
    'a path with a query string' => '/wp-login.php?redirect_to=%2Fwp-admin%2F',
    'a path with a fragment' => '/x.php#top',
    'a percent encoded dot' => '/%2eenv',
    'a percent encoded wp' => '/wp%2Dlogin.php',
    'several leading slashes' => '//.env',
    // fix round 1 (I2): what scanners also ask for ...
    '/cgi-bin/luci',
    '/.aws/credentials',
    '/actuator/health',
    '/.DS_Store',
    '/backup.tar.gz',
    '/server-status',
    '/_ignition/execute-solution',
    '/.svn/entries',
    'a dot folder deeper down' => '/laravel/.env',
    '/boaform/admin/formLogin',
    '/wordpress/wp-login.php',
    '/pma/index.php',
    '/solr/admin/info/system',
    '/jenkins/login',
    '/console',
    '/config.yml',
    '/debug.log',
    '/login.jsp',
    '/site.rar',
    // ... and what browsers ask for by themselves (browser_noise_paths)
    '/apple-touch-icon.png',
    '/apple-touch-icon-precomposed.png',
    '/apple-touch-icon-120x120.png',
    '/browserconfig.xml',
    '/service-worker.js',
    '/sw.js',
    '/manifest.json',
    '/build/assets/app-Bx9a2c1d.js.map',
    '/.well-known/assetlinks.json',
    '/.well-known/apple-app-site-association',
    '/.well-known/traffic-advice',
    '/.well-known/security.txt',
]);

it('reads a path without its leading slash the way Request::path() gives it', function (string $path) {
    expect(ErrorFingerprint::isScannerPath($path))->toBeTrue();
})->with([
    'wp-admin/setup-config.php',
    '.git/config',
    'phpmyadmin',
    'vendor/x',
    '.env',
    'wp-login.php?x=1',
]);

it('does not take a page of ours for a scanner', function (string $path) {
    expect(ErrorFingerprint::isScannerPath($path))->toBeFalse();
})->with([
    '/app/customers',
    '/pricing',
    '/ar/pricing',
    '/',
    '',
    'app/customers',
    'pricing',
    '/app/customers/550e8400-e29b-41d4-a716-446655440000',
    '/pricing-typo',
    'a scanner name only in the query string' => '/pricing?next=/x.php',
    'a scanner name only in the fragment' => '/pricing#.env',
    'a folder that only starts like vendor' => '/vendors',
]);

it('reads the scanner paths from the config', function () {
    config(['qistas.errors.scanner_paths' => ['#^/secret#i']]);

    expect(ErrorFingerprint::isScannerPath('/secret/area'))->toBeTrue()
        ->and(ErrorFingerprint::isScannerPath('secret'))->toBeTrue()
        ->and(ErrorFingerprint::isScannerPath('/wp-login.php'))->toBeFalse();
});

it('skips a pattern that is broken and a config that is not a list, and never throws', function () {
    config(['qistas.errors.scanner_paths' => ['#(unclosed', 42, '', '#^/ok#i']]);

    expect(ErrorFingerprint::isScannerPath('/ok'))->toBeTrue()
        ->and(ErrorFingerprint::isScannerPath('/other'))->toBeFalse();

    config(['qistas.errors.scanner_paths' => 'oops']);
    expect(ErrorFingerprint::isScannerPath('/wp-login.php'))->toBeFalse();

    config(['qistas.errors.scanner_paths' => null]);
    expect(ErrorFingerprint::isScannerPath('/wp-login.php'))->toBeFalse();
});
