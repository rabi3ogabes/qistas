<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorScrubber;
use Carbon\CarbonImmutable;

/*
| The scrubber is the privacy gate of the error center (design section 6): everything that is stored passes through it.
| The table below has one row per kind of personal data, written the way it really shows up in a PHP, Laravel, JavaScript
| or Dart error. "readable text" is the other half: what must NOT be touched, or issues become unreadable and the
| fingerprint loses its signal.
*/

/** A full event the way a recorder would build it, with any field replaced. */
function errorsEvent(array $with = []): ErrorEvent
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

dataset('errors personal data', [
    // e-mail addresses
    'an e-mail address' => ['Mail to ahmed.ali+shop@example.co.uk bounced', 'Mail to [email] bounced'],
    'an e-mail address inside Arabic text' => ['فشل الإرسال إلى ahmed@example.com أمس', 'فشل الإرسال إلى [email] أمس'],

    // phone numbers
    'a Saudi mobile number' => ['No customer with phone 0501234567', 'No customer with phone [phone]'],
    'a Saudi mobile number with spaces' => ['No customer with phone 050 123 4567', 'No customer with phone [phone]'],
    'a Saudi number with the country code' => ['Sending to +966 50 123 4567 failed', 'Sending to [phone] failed'],
    'a Saudi number written with 00' => ['SMS to 00966501234567 rejected', 'SMS to [phone] rejected'],
    'a Qatari number' => ['Invalid recipient +974 5555 1234', 'Invalid recipient [phone]'],
    'a Qatari number without the country code' => ['Call 5555 1234 about it', 'Call [phone] about it'],
    'an international number' => ['Reply from +44 20 7946 0958 ignored', 'Reply from [phone] ignored'],
    'a number in Arabic-Indic digits' => ['الجوال ٠٥٠١٢٣٤٥٦٧ غير صالح', 'الجوال [phone] غير صالح'],
    'a number with the country code in Arabic-Indic digits' => ['إلى +٩٦٦ ٥٠ ١٢٣ ٤٥٦٧ فشل', 'إلى [phone] فشل'],
    'a number in Persian digits' => ['شماره ۰۵۰۱۲۳۴۵۶۷ نامعتبر', 'شماره [phone] نامعتبر'],
    'a number in mixed digits' => ['Phone 05٠1234567 invalid', 'Phone [phone] invalid'],

    // identity numbers, bank accounts and cards
    'a ten digit national id' => ['National id 1234567890 is invalid', 'National id [id] is invalid'],
    'a national id in Arabic-Indic digits' => ['الهوية ١٢٣٤٥٦٧٨٩٠ غير صالحة', 'الهوية [id] غير صالحة'],
    'a nine digit number' => ['Id 123456789 not found', 'Id [id] not found'],
    'an Emirates id' => ['Emirates id 784-1990-1234567-1 expired', 'Emirates id [id] expired'],
    'an IBAN' => ['Account SA0380000000608010167519 not found', 'Account [id] not found'],
    'an IBAN in groups' => ['Pay to SA03 8000 0000 6080 1016 7519 today', 'Pay to [id] today'],
    'a Qatari IBAN' => ['Pay to QA58DOHB00001234567890ABCDEFG today', 'Pay to [id] today'],
    'a card number' => ['Card 4111111111111111 declined', 'Card [id] declined'],
    'a card number in groups' => ['Card 4111 1111 1111 1111 declined', 'Card [id] declined'],
    'a card number with dashes' => ['Card 4111-1111-1111-1111 declined', 'Card [id] declined'],
    'a record id' => ['No query results for 550e8400-e29b-41d4-a716-446655440000', 'No query results for [id]'],

    // tokens
    'a bearer token' => ['Rejected Bearer 9f8e7d6c5b4a3210', 'Rejected Bearer [token]'],
    'a Sanctum token' => ['Token 12|'.str_repeat('aB3dE5gH7j', 4).' revoked', 'Token [token] revoked'],
    'a JWT' => ['JWT eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk expired', 'JWT [token] expired'],
    'a 40 character hex run' => ['Hash da39a3ee5e6b4b0d3255bfef95601890afd80709 mismatch', 'Hash [token] mismatch'],
    'a base64 run' => ['Blob ZXhhbXBsZVNlY3JldEtleVZhbHVlMTIzNDU2Nzg5MA== rejected', 'Blob [token] rejected'],
    'a base64url run' => ['Blob dG9rZW5fdmFsdWVfMTIzNDU2Nzg5MF9hYmNkZWY-xyz rejected', 'Blob [token] rejected'],

    // secrets by name, in any case and any shape
    'a password' => ['Login failed password=hunter2 for the user', 'Login failed password=[secret] for the user'],
    'a password in capitals' => ['PASSWORD=hunter2', 'PASSWORD=[secret]'],
    'an environment password' => ['Bad config DB_PASSWORD=hunter2', 'Bad config DB_PASSWORD=[secret]'],
    'a token' => ['Rejected token=abc now', 'Rejected token=[secret] now'],
    'a secret' => ['Bad client_secret=s3cr3t-value', 'Bad client_secret=[secret]'],
    'a key' => ['Bad api_key: 1234abcd', 'Bad api_key: [secret]'],
    'a key in capitals' => ['Bad KEY=abcd', 'Bad KEY=[secret]'],
    'a PHP array dump' => ["['password' => 'hunter2']", "['password' => [secret]]"],
    'a JSON body' => ['{"token":"abc123"}', '{"token":[secret]}'],
    'an environment value' => ['Config DB_HOST=db.internal missing', 'Config DB_HOST=[secret] missing'],
    'an authorization header' => ['Authorization: Bearer x', 'Authorization: [secret]'],
    'an authorization header in capitals' => ['AUTHORIZATION: Basic dXNlcjpwYXNz', 'AUTHORIZATION: [secret]'],
    'a cookie header' => ['Cookie: a=b', 'Cookie: [secret]'],
    'a cookie header in capitals' => ['COOKIE: laravel_session=abc; XSRF-TOKEN=def', 'COOKIE: [secret]'],
    'a set-cookie header' => ['Set-Cookie: sid=1; Path=/', 'Set-Cookie: [secret]'],

    // addresses
    'an address with user info and a query' => ['https://user:pass@host/x?token=1', 'https://host/x'],
    'an address with a query' => ['GET https://qistas.app/app/customers?search=Ahmed&page=2 failed', 'GET https://qistas.app/app/customers failed'],
    'an address with a fragment' => ['Back to https://x.example/cb#access_token=abc', 'Back to https://x.example/cb'],
    'a database address' => ['Bad DSN postgres://app:s3cret@db.example.com:5432/qistas', 'Bad DSN postgres://db.example.com:5432/qistas'],
    'a path with a query' => ['Not found: /app/customers?phone=0501234567', 'Not found: /app/customers'],
    'an IP address' => ['Refused by 10.0.0.15:6379', 'Refused by [ip]:6379'],

    // SQL: only the statement with ? is kept
    'a quoted literal in a select' => [
        'select * from "contracts" where "customer_name" = \'Ahmed Ali\' and "phone" = \'0501234567\' limit 25',
        'select * from "contracts" where "customer_name" = ? and "phone" = ? limit ?',
    ],
    'an unquoted literal in an update' => [
        'update "customers" set "name" = Ahmed Ali, "phone" = 0501234567 where "id" = 42',
        'update "customers" set "name" = ?, "phone" = ? where "id" = ?',
    ],
    'a raw statement' => [
        'select count(*) as aggregate from customers where tenant_id = 7 and phone = \'0501234567\'',
        'select count(*) as aggregate from customers where tenant_id = ? and phone = ?',
    ],
    'a statement over several lines' => [
        "select *\nfrom \"users\"\nwhere \"email\" = 'a@b.com'",
        "select *\nfrom \"users\"\nwhere \"email\" = ?",
    ],
    'a statement that already has placeholders' => [
        'select * from "customers" where "tenant_id" = ? and "id" in (?, ?) order by "created_at" desc',
        'select * from "customers" where "tenant_id" = ? and "id" in (?, ?) order by "created_at" desc',
    ],
    'a sub-select' => [
        'select * from "customers" where "id" in (select "customer_id" from "contracts" where "status" = late) and "name" = Ahmed Ali order by "id" desc',
        'select * from "customers" where "id" in (select "customer_id" from "contracts" where "status" = ?) and "name" = ? order by "id" desc',
    ],
    'a join' => [
        'select "c"."id" from "contracts" as "c" inner join "customers" on "customers"."id" = "c"."customer_id" where "c"."note" = Paid limit 10',
        'select "c"."id" from "contracts" as "c" inner join "customers" on "customers"."id" = "c"."customer_id" where "c"."note" = ? limit ?',
    ],
    'a function in a statement' => [
        'select * from "customers" where lower("name") like %ahmed ali% and "created_at" >= 2026-11-04 10:30:00',
        'select * from "customers" where lower("name") like ? and "created_at" >= ?',
    ],
    'a statement with a table expression' => [
        'with "t" as (select * from "x" where "y" = Bob) select * from "t"',
        'with "t" as (select * from "x" where "y" = ?) select * from "t"',
    ],
    'a PostgreSQL not-null violation' => [
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"customers\" violates not-null constraint\n"
            .'DETAIL:  Failing row contains (550e8400-e29b-41d4-a716-446655440000, null, 0501234567). (Connection: pgsql, Host: aws-0-ap-south-1.pooler.supabase.com, Port: 6543, Database: postgres, SQL: insert into "customers" ("tenant_id", "name", "phone") values (550e8400-e29b-41d4-a716-446655440000, Ahmed Ali, 0501234567))',
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"customers\" violates not-null constraint\n"
            .'DETAIL:  Failing row contains (?). (Connection: pgsql, SQL: insert into "customers" ("tenant_id", "name", "phone") values (?, ?, ?))',
    ],
    'a PostgreSQL foreign key violation' => [
        "SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table \"contracts\" violates foreign key constraint \"contracts_customer_id_foreign\"\n"
            .'DETAIL:  Key (customer_id)=(550e8400-e29b-41d4-a716-446655440000) is not present in table "customers". (Connection: pgsql, SQL: insert into "contracts" ("customer_id") values (550e8400-e29b-41d4-a716-446655440000))',
        "SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table \"contracts\" violates foreign key constraint \"contracts_customer_id_foreign\"\n"
            .'DETAIL:  Key (customer_id)=(?) is not present in table "customers". (Connection: pgsql, SQL: insert into "contracts" ("customer_id") values (?))',
    ],
    'a query exception' => [
        'SQLSTATE[HY000]: General error (Connection: sqlite, Database: :memory:, SQL: select * from "users" where "email" = a@b.com)',
        'SQLSTATE[HY000]: General error (Connection: sqlite, SQL: select * from "users" where "email" = ?)',
    ],
    'a PostgreSQL unique violation' => [
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\n"
            .'DETAIL:  Key (email)=(a@b.com) already exists. (Connection: pgsql, Host: db.example.com, Port: 5432, Database: postgres, SQL: insert into "users" ("name", "email") values (Ahmed Ali, a@b.com))',
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\n"
            .'DETAIL:  Key (email)=(?) already exists. (Connection: pgsql, SQL: insert into "users" ("name", "email") values (?, ?))',
    ],
    'a PostgreSQL value that is not valid' => [
        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "Ahmed Ali"',
        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "?"',
    ],
    'a PostgreSQL failing row' => [
        'DETAIL:  Failing row contains (1, Ahmed Ali, 0501234567, null).',
        'DETAIL:  Failing row contains (?).',
    ],
    'a MySQL duplicate entry' => [
        "Duplicate entry 'a@b.com' for key 'users_email_unique'",
        "Duplicate entry '?' for key 'users_email_unique'",
    ],
    'a database connection failure' => [
        'SQLSTATE[08006] [7] connection to server at "db.abc123.supabase.co" (13.126.1.2), port 5432 failed: FATAL:  password authentication failed for user "postgres.abcdefgh"',
        'SQLSTATE[08006] [7] connection to server at "[host]", port 5432 failed: FATAL:  password authentication failed for user "[secret]"',
    ],

    // paths: only the part under the project root
    'a Linux path' => ['include(/var/www/site/web/app/Foo.php): Failed to open stream', 'include(app/Foo.php): Failed to open stream'],
    'a vendor path' => ['/var/www/site/web/vendor/laravel/framework/src/Illuminate/Support/Str.php', 'vendor/laravel/framework/src/Illuminate/Support/Str.php'],
    'a Windows path' => ['C:\\Users\\x\\proj\\web\\app\\A.php', 'app/A.php'],
    'a Windows path with a space in a folder name' => ['Error in C:\\Users\\Rabii Souai\\proj\\web\\app\\A.php on line 12', 'Error in app/A.php on line 12'],
    'a Windows path with forward slashes' => ['Error in C:/Users/x/proj/web/app/A.php', 'Error in app/A.php'],
    'a path outside the project' => ['Cannot write /home/rabii/.cache/qistas/x.db now', 'Cannot write [path] now'],
    'a Windows path outside the project' => ['Cannot write C:\\Windows\\Temp\\php1A2B.tmp now', 'Cannot write [path] now'],
    'a file address' => ['Error in file:///C:/Users/x/proj/web/app/A.php', 'Error in app/A.php'],
]);

dataset('errors readable text', [
    'a line number' => 'Error in Foo.php:12',
    'a frame with a line number' => 'at app/Http/Controllers/CustomerController.php(87)',
    'a PHP class name' => 'Illuminate\Database\QueryException',
    'a long PHP class name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
    'another long PHP class name' => 'Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance',
    'a Symfony class name' => 'Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException',
    'a namespaced class name with digits' => 'League\OAuth2\Server\Exception\OAuthServerException',
    'a long class name with a digit' => 'Class App\Services\Billing\Stripe3DSecureChallengeHandler not found',
    'another long class name with a digit' => 'Call to undefined method App\Http\Psr7ResponseInterfaceFactory::make()',
    'an SQLSTATE code' => 'SQLSTATE[23505]: Unique violation',
    'an HTTP status' => 'HTTP 500 Internal Server Error',
    'a status code' => 'The server answered with status code 429',
    'a memory limit' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
    'a time limit' => 'Maximum execution time of 30 seconds exceeded',
    'an undefined method' => 'Call to undefined method App\Models\Customer::foo()',
    'an undefined array key' => 'Undefined array key "name"',
    'a missing class' => 'Class "App\Missing" not found',
    'a missing route' => 'Route [admin.errors.index] not defined.',
    'a missing view' => 'View [admin.errors.index] not found.',
    'a missing config key' => 'config(database.connections.pgsql) is not set',
    'a JavaScript syntax error' => 'Unexpected token \'<\', "<!DOCTYPE "... is not valid JSON',
    'a JavaScript type error' => "TypeError: Cannot read properties of undefined (reading 'id')",
    'a versions line' => 'App 1.30.0 on Android 14, PHP 8.4.25',
    'a date and time' => 'Failed at 2026-11-04 10:30:00 after 3 tries',
    'a clock time' => 'Server error at 12:30:45',
    'an amount' => 'Allocation 1250.5000 exceeds the balance',
    'a long decimal' => 'Ratio 0.33333333333333 is not 1.0000000000123',
    'a constraint name' => 'UNIQUE constraint failed: customers.national_id_hash',
    'a missing table' => 'SQLSTATE[HY000]: General error: 1 no such table: customers',
    'a missing column named like a secret' => 'SQLSTATE[HY000]: General error: 1 no such column: token',
    'a duplicate key without the value' => 'duplicate key value violates unique constraint "error_issue_people_pkey"',
    'a long constraint name' => 'violates foreign key constraint "error_occurrences_issue_id_foreign"',
    'a route pattern' => '/app/customers/{id}',
    'an admin route' => '/admin/errors',
    'a plain request line' => 'GET /api/v1/customers/{customer} answered 500',
    'a verb that is also SQL' => 'Please select an item from the list',
    'a Dart frame' => '#0      main.<anonymous closure> (package:qistas/features/home/home_screen.dart:128:7)',
    'a JavaScript frame' => 'at onClick (https://qistas.app/build/assets/app-Bx9a2c.js:1:2345)',
    'Arabic text' => 'حدث خطأ أثناء حفظ العقد، حاول مرة أخرى',
]);

it('removes personal data from a message', function (string $input, string $expected) {
    expect(ErrorScrubber::text($input))->toBe($expected);
})->with('errors personal data');

it('removes personal data from a trace too', function (string $input, string $expected) {
    expect(ErrorScrubber::trace($input))->toBe($expected);
})->with('errors personal data');

it('is idempotent', function (string $input) {
    $once = ErrorScrubber::text($input);

    expect(ErrorScrubber::text($once))->toBe($once)
        ->and(ErrorScrubber::trace(ErrorScrubber::trace($input)))->toBe(ErrorScrubber::trace($input));
})->with('errors personal data');

it('leaves readable text alone', function (string $input) {
    expect(ErrorScrubber::text($input))->toBe($input)
        ->and(ErrorScrubber::trace($input))->toBe($input);
})->with('errors readable text');

it('leaves none of the values in a message that carries all of them', function () {
    $values = [
        'ahmed.ali@example.com', '0501234567', '+966 50 123 4567', '00966501234567', '+974 5555 1234', '٠٥٠١٢٣٤٥٦٧',
        '۰۵۰۱۲۳۴۵۶۷', '1234567890', 'SA0380000000608010167519', '4111 1111 1111 1111', 'hunter2', 's3cr3tvalue',
        'da39a3ee5e6b4b0d3255bfef95601890afd80709', 'Ahmed Ali', 'rabii', 'Souai', '550e8400-e29b-41d4-a716-446655440000',
    ];
    $message = implode("\n", [
        'Customer ahmed.ali@example.com phone 0501234567 or +966 50 123 4567 or 00966501234567 or +974 5555 1234',
        'Arabic ٠٥٠١٢٣٤٥٦٧ Persian ۰۵۰۱۲۳۴۵۶۷ id 1234567890 iban SA0380000000608010167519 card 4111 1111 1111 1111',
        'password=hunter2 client_secret=s3cr3tvalue hash da39a3ee5e6b4b0d3255bfef95601890afd80709',
        'select * from "customers" where "name" = Ahmed Ali and "id" = 550e8400-e29b-41d4-a716-446655440000',
        'in C:\\Users\\rabii\\Souai\\web\\app\\Foo.php and /home/rabii/site/storage/x.log',
    ]);

    $clean = ErrorScrubber::text($message, 5000);

    foreach ($values as $value) {
        expect($clean)->not->toContain($value);
    }
});

it('cuts at the limit on a character boundary', function () {
    $arabic = str_repeat('خطأ في الاتصال بقاعدة البيانات ', 60);

    $cut = ErrorScrubber::text($arabic);

    expect(mb_strlen($cut))->toBeLessThanOrEqual(500)
        ->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and(str_starts_with($arabic, $cut))->toBeTrue()
        ->and(json_encode($cut))->not->toBeFalse()
        ->and(mb_strlen(ErrorScrubber::text($arabic, 50)))->toBeLessThanOrEqual(50);
});

it('cuts after masking, so a cut never leaves half of an address', function () {
    $message = str_repeat('x', 495).' ahmed.ali@example.com tail';

    $cut = ErrorScrubber::text($message);

    expect($cut)->not->toContain('ahmed')
        ->and($cut)->not->toContain('@')
        ->and($cut)->not->toMatch('/\[[a-z]*$/')
        ->and(mb_strlen($cut))->toBeLessThanOrEqual(500);
});

it('survives invalid UTF-8', function () {
    $text = "bad \xC3\x28 byte \xFF a@b.com \xE2\x82 end";

    $clean = ErrorScrubber::text($text);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and(json_encode($clean))->not->toBeFalse()
        ->and($clean)->toContain('[email]')
        ->and($clean)->not->toContain('a@b.com')
        ->and(mb_check_encoding(ErrorScrubber::trace($text), 'UTF-8'))->toBeTrue();
});

it('drops control and invisible characters that could hide or split a value', function () {
    $clean = ErrorScrubber::text("Phone 050\u{200E}1234\u{200B}567 \u{202E}evil\x00 here");

    expect($clean)->toBe('Phone [phone] evil here');
});

it('keeps the lines of a message and turns every line ending into a line feed', function () {
    expect(ErrorScrubber::text("first line\r\nsecond line\rthird"))->toBe("first line\nsecond line\nthird");
});

it('fails closed: a text the patterns cannot handle is dropped, never kept as it was', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '10');

    try {
        $clean = ErrorScrubber::text(str_repeat('call 0501234567 or a@b.com. ', 40));
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', (string) $jit);
    }

    expect($clean)->toBe('[removed]');
});

it('masks every number it is given, however long the input', function () {
    $clean = ErrorScrubber::text(str_repeat('0501234567 ', 20_000), 100_000);

    expect($clean)->not->toContain('0501234567');
});

it('keeps no value of a document written inside a statement', function () {
    $clean = ErrorScrubber::text('insert into "audit_logs" ("id", "metadata") values (1, {"name":"Ahmed","phone":"0501234567"})');

    expect($clean)->toStartWith('insert into "audit_logs" ("id", "metadata") values (?')
        ->and($clean)->not->toContain('Ahmed')
        ->and($clean)->not->toContain('0501234567')
        ->and(ErrorScrubber::text($clean))->toBe($clean);
});

it('does not give a statement back its values when a name has an apostrophe', function () {
    $clean = ErrorScrubber::text('update "customers" set "name" = O\'Brien, "phone" = 0501234567 where "id" = 5');

    expect($clean)->not->toContain('Brien')
        ->and($clean)->not->toContain('0501234567')
        ->and($clean)->toStartWith('update "customers" set "name" = ?');
});

it('is not slowed down by a text built to make a pattern run for ever', function (string $unit) {
    $hostile = substr(str_repeat($unit, 40_000), 0, 60_000);

    $started = microtime(true);
    $clean = ErrorScrubber::trace($hostile);

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(strlen($clean))->toBeLessThanOrEqual(8192);
})->with(['_', '-', '.', 'a.', '0 ', 'a@', '/a', 'aA1_', 'C:\\a ', 'select a, ', 'Key (a)=(', '(']);

it('cuts a trace to 8192 bytes on a character boundary', function () {
    $trace = implode("\n", array_map(
        fn (int $i) => "#{$i} /var/www/site/web/app/Services/خدمة/Foo.php(12): Foo->bar()",
        range(0, 400),
    ));

    $cut = ErrorScrubber::trace($trace);

    expect(strlen($cut))->toBeLessThanOrEqual(8192)
        ->and(strlen($cut))->toBeGreaterThan(8000)
        ->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and($cut)->toStartWith("#0 app/Services/خدمة/Foo.php(12): Foo->bar()\n#1 app/")
        ->and(strlen(ErrorScrubber::trace($trace, 1000)))->toBeLessThanOrEqual(1000);
});

it('cuts the file paths of a PHP trace and drops the arguments of every frame', function () {
    $trace = implode("\n", [
        "#0 /var/www/site/web/app/Foo.php(12): Foo->bar('Ahmed Ali', 'a@b.com', Array)",
        '#1 /var/www/site/web/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(180): App\\Http\\Middleware\\Thing->handle(Object(Illuminate\\Http\\Request), Object(Closure))',
        "#2 [internal function]: Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}('x@y.com')",
        '#3 {main}',
    ]);

    expect(ErrorScrubber::trace($trace))->toBe(implode("\n", [
        '#0 app/Foo.php(12): Foo->bar()',
        '#1 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(180): App\Http\Middleware\Thing->handle()',
        '#2 [internal function]: Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}()',
        '#3 {main}',
    ]));
});

it('cuts the project root whichever slash the path uses', function () {
    $root = base_path();
    $forward = str_replace('\\', '/', $root).'/app/Foo.php';
    $backward = str_replace('/', '\\', $root).'\\app\\Foo.php';

    expect(ErrorScrubber::trace("#0 {$forward}(12): Foo->bar()"))->toBe('#0 app/Foo.php(12): Foo->bar()')
        ->and(ErrorScrubber::trace("#0 {$backward}(12): Foo->bar()"))->toBe('#0 app/Foo.php(12): Foo->bar()')
        ->and(ErrorScrubber::text("Cannot read {$forward}: denied"))->toBe('Cannot read app/Foo.php: denied');
});

it('keeps the frames of a JavaScript or Dart trace and drops what rides on the address', function () {
    $trace = implode("\n", [
        'TypeError: x is undefined',
        '    at render (https://qistas.app/build/assets/app-Bx9a2c.js?v=3&user=ahmed:1:2345)',
        '    at https://user:pw@qistas.app/build/assets/vendor-Qk81zz.js:7:99',
        '#0      Foo.bar (package:qistas/core/foo.dart:12:3)',
        '#1      _rootRunUnary (dart:async/zone.dart:1407:47)',
    ]);

    expect(ErrorScrubber::trace($trace))->toBe(implode("\n", [
        'TypeError: x is undefined',
        '    at render (https://qistas.app/build/assets/app-Bx9a2c.js)',
        '    at https://qistas.app/build/assets/vendor-Qk81zz.js:7:99',
        '#0      Foo.bar (package:qistas/core/foo.dart:12:3)',
        '#1      _rootRunUnary (dart:async/zone.dart:1407:47)',
    ]));
});

it('reduces a route to its pattern', function (?string $route, ?string $expected) {
    expect(ErrorScrubber::route($route))->toBe($expected);
})->with([
    'nothing' => [null, null],
    'an empty route' => ['', null],
    'a pattern' => ['app/customers/{customer}', 'app/customers/{customer}'],
    'an optional parameter' => ['/app/customers/{customer?}', '/app/customers/{customer?}'],
    'a plain route' => ['/admin/errors', '/admin/errors'],
    'a route that looks like a system path' => ['/home/dashboard', '/home/dashboard'],
    'a query string' => ['/app/customers?search=Ahmed&page=2', '/app/customers'],
    'a query string after an id' => ['/app/customers/12/edit?tab=notes', '/app/customers/{id}/edit'],
    'a fragment' => ['/help/topic#section', '/help/topic'],
    'a number' => ['/api/v1/contracts/12', '/api/v1/contracts/{id}'],
    'a phone number' => ['/p/0501234567', '/p/{id}'],
    'an encoded phone number' => ['/p/%2B966501234567', '/p/[phone]'],
    'a uuid' => ['/api/v1/contracts/550e8400-e29b-41d4-a716-446655440000/payments', '/api/v1/contracts/{id}/payments'],
    'a ulid' => ['/x/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/x/{id}'],
    'a hex id' => ['/reset/9f8e7d6c5b4a32109f8e7d6c5b4a3210', '/reset/{id}'],
    'a mixed token' => ['/join/AbC123XyZ456AbC123XyZ', '/join/{id}'],
    'an e-mail address' => ['/unsubscribe/a@example.com', '/unsubscribe/[email]'],
    'a slug' => ['/blog/how-to-record-a-payment', '/blog/how-to-record-a-payment'],
]);

it('clamps every field of an event to the width of its column, after masking', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'source' => str_repeat('s', 40),
        'kind' => str_repeat('k', 40),
        'class' => str_repeat('Z', 300),
        'message' => str_repeat('m', 900),
        'trace' => str_repeat("#0 app/Foo.php(12): Foo->bar()\n", 600),
        'method' => 'PROPPATCH',
        'route' => '/app/'.str_repeat('r', 300),
        'version' => str_repeat('v', 40),
        'platform' => 'platformxx',
        'device' => 'televisions',
    ]));

    expect($event->source)->toHaveLength(16)
        ->and($event->kind)->toHaveLength(16)
        ->and($event->class)->toHaveLength(160)
        ->and(mb_strlen($event->message))->toBe(500)
        ->and(strlen($event->trace))->toBeLessThanOrEqual(8192)
        ->and($event->method)->toBe('PROPPATC')
        ->and($event->route)->toHaveLength(160)
        ->and($event->version)->toHaveLength(16)
        ->and($event->platform)->toBe('platform')
        ->and($event->device)->toBe('televisi');
});

it('clamps a 300 character class and route, and a 40 character version', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'class' => 'App\\'.str_repeat('Namespace\\', 40).'Thing',
        'route' => '/'.implode('/', array_fill(0, 60, 'segment')),
        'version' => '1.30.0-beta.'.str_repeat('x', 28),
    ]));

    expect(mb_strlen($event->class))->toBe(160)
        ->and(mb_strlen((string) $event->route))->toBe(160)
        ->and($event->version)->toBe(substr('1.30.0-beta.'.str_repeat('x', 28), 0, 16));
});

it('clamps Arabic text without cutting a character in half', function () {
    $arabic = str_repeat('خطأ في الاتصال ', 80);

    $event = ErrorScrubber::event(errorsEvent([
        'class' => $arabic,
        'message' => $arabic,
        'trace' => str_repeat("#0 خطأ في الاتصال\n", 700),
        'route' => '/'.str_repeat('خطأ', 100),
        'version' => str_repeat('خ', 40),
    ]));

    foreach ([$event->class, $event->message, $event->trace, $event->route, $event->version] as $value) {
        expect(mb_check_encoding((string) $value, 'UTF-8'))->toBeTrue()
            ->and(json_encode($value))->not->toBeFalse();
    }

    expect(mb_strlen($event->class))->toBeLessThanOrEqual(160)
        ->and(mb_strlen($event->message))->toBeLessThanOrEqual(500)
        ->and(strlen($event->trace))->toBeLessThanOrEqual(8192)
        ->and(mb_strlen((string) $event->route))->toBeLessThanOrEqual(160)
        ->and(mb_strlen((string) $event->version))->toBe(16);
});

it('returns a clean short event unchanged', function () {
    $event = errorsEvent();

    expect(ErrorScrubber::event($event)->toArray())->toBe($event->toArray());

    $nothing = new ErrorEvent(source: 'app', kind: 'crash', platform: 'android', at: '2026-11-04T10:30:00+00:00');

    expect(ErrorScrubber::event($nothing)->toArray())->toBe($nothing->toArray());
});

it('cleans every string field of an event and leaves numbers and enums alone', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'source' => 'api',
        'kind' => 'exception',
        'class' => 'Mail\\a@b.com\\Failure',
        'message' => 'Could not text 0501234567 (password=hunter2)',
        'trace' => "#0 /var/www/site/web/app/Foo.php(12): Foo->bar('Ahmed')\n#1 C:\\Users\\x\\proj\\web\\app\\B.php(3): B->c()",
        'status' => 503,
        'method' => 'POST',
        'route' => '/api/v1/customers/550e8400-e29b-41d4-a716-446655440000?phone=0501234567',
        'version' => '1.30.0',
        'platform' => 'android',
        'device' => 'tablet',
    ]));

    expect($event->source)->toBe('api')
        ->and($event->kind)->toBe('exception')
        ->and($event->class)->toBe('Mail\[email]\Failure')
        ->and($event->message)->toBe('Could not text [phone] (password=[secret])')
        ->and($event->trace)->toBe("#0 app/Foo.php(12): Foo->bar()\n#1 app/B.php(3): B->c()")
        ->and($event->status)->toBe(503)
        ->and($event->method)->toBe('POST')
        ->and($event->route)->toBe('/api/v1/customers/{id}')
        ->and($event->version)->toBe('1.30.0')
        ->and($event->platform)->toBe('android')
        ->and($event->device)->toBe('tablet')
        ->and($event->userId)->toBe('9d2f4c7e-0b1a-4c55-8f3e-1a2b3c4d5e6f')
        ->and($event->at)->toBe('2026-11-04T10:30:00+00:00');
});

it('cleans the version and the method an app or a browser reported', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'method' => "GE\nT",
        'version' => 'a@b.com',
    ]));

    expect($event->method)->toBe('GE T')
        ->and($event->version)->toBe('[email]');
});

it('strips spaces and control characters from the words that are only cut', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'source' => "si\x00te",
        'kind' => 'exce ption',
        'platform' => "ser\nver",
        'device' => "desk\u{200E}top",
    ]));

    expect($event->source)->toBe('site')
        ->and($event->kind)->toBe('exception')
        ->and($event->platform)->toBe('server')
        ->and($event->device)->toBe('desktop');
});

it('turns an empty optional field into null', function () {
    $event = ErrorScrubber::event(errorsEvent(['method' => '  ', 'route' => '', 'version' => '', 'device' => '']));

    expect($event->method)->toBeNull()
        ->and($event->route)->toBeNull()
        ->and($event->version)->toBeNull()
        ->and($event->device)->toBeNull();
});

it('scrubs an event twice to the same result', function () {
    $once = ErrorScrubber::event(errorsEvent([
        'class' => 'Mail\\a@b.com\\Failure',
        'message' => 'Could not text 0501234567 password=hunter2 select * from "users" where "email" = a@b.com',
        'trace' => "#0 /var/www/site/web/app/Foo.php(12): Foo->bar('Ahmed')",
        'route' => '/api/v1/customers/12?x=1',
    ]));

    expect(ErrorScrubber::event($once)->toArray())->toBe($once->toArray());
});

it('gives an event its defaults', function () {
    $event = new ErrorEvent(source: 'site', kind: 'http');

    expect($event->class)->toBe('')
        ->and($event->message)->toBe('')
        ->and($event->trace)->toBe('')
        ->and($event->status)->toBeNull()
        ->and($event->method)->toBeNull()
        ->and($event->route)->toBeNull()
        ->and($event->version)->toBeNull()
        ->and($event->platform)->toBe('server')
        ->and($event->device)->toBeNull()
        ->and($event->userId)->toBeNull()
        ->and(CarbonImmutable::parse($event->at)->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and($event->at)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

it('round-trips an event through toArray and fromArray, and through the JSON line of the spool', function () {
    $event = errorsEvent(['message' => 'فشل الحفظ', 'status' => 500]);

    $copy = ErrorEvent::fromArray($event->toArray());
    $fromLine = ErrorEvent::fromArray(json_decode(json_encode($event->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

    expect($copy)->toEqual($event)
        ->and($fromLine)->toEqual($event)
        ->and(array_keys($event->toArray()))->toBe([
            'source', 'kind', 'class', 'message', 'trace', 'status', 'method', 'route', 'version', 'platform', 'device', 'user_id', 'at',
        ]);

    $minimal = new ErrorEvent(source: 'app', kind: 'crash', platform: 'ios');

    expect(ErrorEvent::fromArray($minimal->toArray()))->toEqual($minimal);
});

it('reads an event back from a damaged line without failing on the wrong type of a field', function () {
    $event = ErrorEvent::fromArray([
        'source' => 'site', 'kind' => 'exception', 'class' => ['x'], 'message' => 12, 'trace' => null, 'status' => '503',
        'method' => ['GET'], 'route' => 5, 'version' => 1.5, 'platform' => null, 'device' => 3, 'user_id' => 9, 'at' => [],
        'extra' => 'ignored',
    ]);

    expect($event->class)->toBe('')
        ->and($event->message)->toBe('')
        ->and($event->trace)->toBe('')
        ->and($event->status)->toBe(503)
        ->and($event->method)->toBeNull()
        ->and($event->route)->toBeNull()
        ->and($event->version)->toBeNull()
        ->and($event->platform)->toBe('server')
        ->and($event->device)->toBeNull()
        ->and($event->userId)->toBeNull()
        ->and($event->at)->toBeString();
});

it('refuses a line with no source or kind', function (array $line) {
    ErrorEvent::fromArray($line);
})->with([
    'nothing' => [[]],
    'no kind' => [['source' => 'site']],
    'no source' => [['kind' => 'http']],
    'a source that is not text' => [['source' => 5, 'kind' => 'http']],
])->throws(InvalidArgumentException::class);
