<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorScrubber;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

/** A real Laravel session cookie (Crypt::encryptString of a random string), as the browser sent it or as PHP decoded it. */
function errorsCookie(bool $encoded = false): string
{
    return $encoded
        ? 'eyJpdiI6IlM1ZGFMRWpxODZCd1U5eGFZaS9WSXc9PSIsInZhbHVlIjoiYkhoUDFyZHZVSUFwbEt4d2VqdnlSaENsTzZqam1UcTY0cFdBTS8rVmZzOTF2Yno3cUlvSmZubXRoVWpFYTlQayIsIm1hYyI6ImYzMzU2MWFiOGVhYjI0MjM2ZDAxYzRkOGVmYTE3MDVkMTBhMmJjMzViM2U0MTY2OTU4NDQxMWQ0ZjlkMGFkOWMiLCJ0YWciOiIifQ%3D%3D'
        : 'eyJpdiI6IkRrS1A5ZXc5UUl3QmZTRHU5NkdWRGc9PSIsInZhbHVlIjoiVWlhK2hhZVRrWDNqdmdSMHpQejhPR2RHTXpaS3hyYnVOOVBiN0ZqUXQxaEt0VlZRWldoblRHaS83TEoraUJCQyIsIm1hYyI6IjdmZDllZjM0NzMxYjM2NmRiMTMzNDVlYWJhNGRiYjliZjczYzQ3YmM2NWIwNWY2YWVhOTVmNGJlNWI2ZjkxYjMiLCJ0YWciOiIifQ==';
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
    // Hardening B3, B4: the last letter of a Qatari IBAN is part of it; a card's tail does not eat the phone after it.
    'a Qatari IBAN in groups' => ['Pay to QA58 DOHB 0000 1234 5678 90AB CDEF G today', 'Pay to [id] today'],
    'a Qatari IBAN in groups at the end' => ['IBAN QA58 DOHB 0000 1234 5678 90AB CDEF G', 'IBAN [id]'],
    'a card and then a phone' => ['card 4111 1111 1111 1111 050 123 4567', 'card [id] [phone]'],
    'a nineteen digit card in groups' => ['Card 6011 0009 9013 9424 123 declined', 'Card [id] declined'],
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
        'select * from "contracts" where "customer_name" = ? and "phone" = ?',
    ],
    'an unquoted literal in an update' => [
        'update "customers" set "name" = Ahmed Ali, "phone" = 0501234567 where "id" = 42',
        'update "customers" set "name" = ?, "phone" = ? where "id" = ?',
    ],
    'a raw statement' => [
        'select count(*) as aggregate from customers where tenant_id = 7 and phone = \'0501234567\'',
        'select count(*) as aggregate from customers where ? = ?',
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
        'select "c"."id" from "contracts" as "c" inner join "customers" on "customers"."id" = "c"."customer_id" where "c"."note" = ?',
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

    // Fix round 1, C1: bindings that Laravel puts into the message of a QueryException, unquoted
    'a note with from inside a binding' => [
        '(Connection: pgsql, SQL: update "customers" set "notes" = Payment from Ahmed Ali Alghamdi in Riyadh, "phone" = 0501234567 where "id" = 5)',
        '(Connection: pgsql, SQL: update "customers" set "notes" = ?, "phone" = ? where "id" = ?)',
    ],
    'a note with select inside a binding' => [
        '(Connection: pgsql, SQL: insert into "notes" ("body", "tenant_id") values (please select Fahad Alotaibi for the visit, 7))',
        '(Connection: pgsql, SQL: insert into "notes" ("body", "tenant_id") values (?, ?))',
    ],
    'a note with into inside a binding' => [
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (moved into Villa Fahad))',
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (?))',
    ],
    'a note with order by inside a binding' => [
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (order by Fahad Alotaibi))',
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (?))',
    ],
    'a note with as inside a binding' => [
        '(Connection: pgsql, SQL: update "customers" set "notes" = known as Abu Fahad, "phone" = 0501234567 where "id" = 5)',
        '(Connection: pgsql, SQL: update "customers" set "notes" = ?, "phone" = ? where "id" = ?)',
    ],
    'a name with a title inside a binding' => [
        '(Connection: pgsql, SQL: update "customers" set "name" = Mr. Fahad Alotaibi where "id" = 5)',
        '(Connection: pgsql, SQL: update "customers" set "name" = ? where "id" = ?)',
    ],
    'a name with another title inside a binding' => [
        '(Connection: pgsql, SQL: insert into "customers" ("name", "phone") values (Dr. Noura Alsubaie, 0501234567))',
        '(Connection: pgsql, SQL: insert into "customers" ("name", "phone") values (?, ?))',
    ],
    'a name with an address in angle brackets inside a binding' => [
        '(Connection: pgsql, SQL: update "users" set "name" = Fahad Alotaibi <fahad@example.com> where "id" = 5)',
        '(Connection: pgsql, SQL: update "users" set "name" = ? where "id" = ?)',
    ],
    'a note with an arrow inside a binding' => [
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (Fahad => paid 500))',
        '(Connection: pgsql, SQL: insert into "notes" ("body") values (?))',
    ],
    'a name in capitals with from inside a binding' => [
        '(Connection: pgsql, SQL: update "customers" set "name" = FAHAD FROM DOHA where "id" = 5)',
        '(Connection: pgsql, SQL: update "customers" set "name" = ? where "id" = ?)',
    ],
    'an address with a line break inside a binding' => [
        "SQLSTATE[23502]: Not null violation (Connection: pgsql, SQL: insert into \"customers\" (\"address\", \"name\") values (Street 5\nFahad Alotaibi Villa 12, null))",
        'SQLSTATE[23502]: Not null violation (Connection: pgsql, SQL: insert into "customers" ("address", "name") values (?, ?))',
    ],
    'a case expression with a name' => [
        '(Connection: pgsql, SQL: select case when "name" = Fahad then 1 else 0 end from "x")',
        '(Connection: pgsql, SQL: select case when "name" = ? end from "x")',
    ],
    'a statement that starts with values' => [
        '(Connection: pgsql, SQL: values (Fahad Alotaibi))',
        '(Connection: pgsql, SQL: values (?))',
    ],
    'a list of names in a statement' => [
        '(Connection: pgsql, SQL: select * from "customers" where "name" in (Fahad, Noura, Salem))',
        '(Connection: pgsql, SQL: select * from "customers" where "name" in (?, ?, ?))',
    ],
    'a name in an escaped E string' => [
        "select * from \"x\" where \"a\" = E'Fahad\\'s'",
        'select * from "x" where "a" = ?',
    ],

    // Fix round 1, C2: the value of a cookie by its name, and by its shape
    'a Laravel session cookie' => ['laravel_session='.errorsCookie(), 'laravel_session=[secret]'],
    'a Laravel session cookie in a list in JSON' => ['{"cookie":["laravel_session='.errorsCookie(true).'"]}', '{"cookie":[[secret]]}'],
    'a session id' => ['sid=kQ9x2LmZp0Vb7TrYw3NcJ8aDh5sFgHjK', 'sid=[secret]'],
    'a remember cookie' => ['remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d='.errorsCookie(true), 'remember_web_[token]=[secret]'],
    'a session cookie value on its own' => ['session '.errorsCookie().' expired', 'session [token] expired'],
    'a session cookie value on its own, URL-encoded' => ['session '.errorsCookie(true).' expired', 'session [token] expired'],

    // Fix round 1, I1: e-mail addresses in the shapes a URL and a log give them
    'an e-mail address with an encoded at' => ['Lookup ahmed%40example.com failed', 'Lookup [email] failed'],
    'an e-mail address in an encoded query' => ['GET /api/v1/customers?email=ahmed%40example.com failed', 'GET /api/v1/customers failed'],
    'an e-mail address in encoded angle brackets' => ['To: %3Cahmed@example.com%3E', 'To: [email]%3E'],
    'an e-mail address after an encoded space' => ['Search %20ahmed@example.com', 'Search [email]'],
    'an e-mail address after a plus' => ['q +ahmed@example.com', 'q [email]'],
    'an e-mail address after an underscore' => ['user__ahmed@example.com', '[email]'],
    'an e-mail address after a dot' => ['.ahmed@example.com', '[email]'],
    'an e-mail address with a quoted name' => ['Mail to "ahmed"@example.com bounced', 'Mail to [email] bounced'],
    'an e-mail address on localhost' => ['ahmed@localhost failed', '[email] failed'],
    'an e-mail address on a punycode domain' => ['Mail to ahmed@xn--mgbh0fb.xn--mgberp4a5d4ar bounced', 'Mail to [email] bounced'],
    'an e-mail address on an address' => ['ahmed@[10.0.0.1] failed', '[email] failed'],
    'an e-mail address in a path' => ['Not found: /app/customers/search/ahmed%40example.com', 'Not found: /app/customers/search/[email]'],
    'an e-mail address in a HTTP client error' => [
        'Client error: `GET https://api.example.com/v1/users/ahmed%40example.com` resulted in a `404 Not Found` response',
        'Client error: `GET https://api.example.com/v1/users/[email]` resulted in a `404 Not Found` response',
    ],
    'an e-mail address with a full-width at' => ['ahmed＠example.com', '[email]'],

    // Fix round 1, I2: Unicode spaces between the groups of a number
    'a number with no-break spaces' => ["+966\u{00A0}50\u{00A0}123\u{00A0}4567", '[phone]'],
    'a number with thin spaces' => ["+966\u{2009}50\u{2009}123\u{2009}4567", '[phone]'],
    'a number with narrow no-break spaces' => ["050\u{202F}123\u{202F}4567", '[phone]'],
    'a number with figure spaces' => ["050\u{2007}123\u{2007}4567", '[phone]'],
    'a number with ideographic spaces' => ["050\u{3000}123\u{3000}4567", '[phone]'],
    'an id with no-break spaces' => ["national id 1234\u{00A0}567\u{00A0}890", 'national id [id]'],
    'a card with no-break spaces' => ["card 4111\u{00A0}1111\u{00A0}1111\u{00A0}1111", 'card [id]'],

    // Fix round 1, I3: Gulf mobile numbers without a plus or a space
    'an unspaced Qatari mobile' => ['Customer 55123456 not found', 'Customer [phone] not found'],
    'a Kuwaiti mobile' => ['phone 99887766', 'phone [phone]'],
    'a Kuwaiti mobile in groups' => ['phone 9988 7766', 'phone [phone]'],
    'a Bahraini mobile' => ['phone 3355 1234', 'phone [phone]'],
    'a Saudi number with the country code and no plus' => ['SMS failed for 966 50 123 4567', 'SMS failed for [phone]'],
    'a Qatari number with the country code and no plus' => ['974 5555 1234', '[phone]'],
    'a Saudi mobile without the zero' => ['phone 50 123 4567', 'phone [phone]'],
    'a Saudi mobile without the zero or the spaces' => ['phone 501234567', 'phone [phone]'],
    'a Saudi number with slashes' => ['050/123/4567', '[phone]'],

    // Fix round 1, I4: tokens that are not hex and not a name
    'a long bearer token behind the word token' => ['Bearer token kQ9x2LmZp0Vb7TrYw3Nc', 'Bearer [token]'],
    'a long bearer token in lower case' => ['bearer kQ9x2LmZp0Vb7TrYw3Nc', 'Bearer [token]'],
    'a key of lower case letters and digits' => ['api 6hd8a9s7d6f5g4h3j2k1l0z9x8c7v6b5', 'api [token]'],
    'a key of upper case letters and digits' => ['code K9X2LMZP0VB7TRYW3NCJ8ADH5SFGHJKL end', 'code [token] end'],
    'a Stripe key' => ['Invalid API Key provided: sk_' . 'test_4eC39HqLyjWDarjtT1zdp7dc', 'Invalid API Key provided: [token]'],
    'standard base64 with a slash' => ['value kQ9x2LmZp0Vb7TrY/w3NcJ8aDh5sFgHjK end', 'value [token] end'],

    // Fix round 1, I5: credentials in dumps, quotes and escapes
    'a print_r dump' => [
        "Array\n(\n    [password] => hunter2\n    [email] => a@b.com\n)",
        "Array\n(\n    [password] => [secret]\n    [email] => [secret]\n)",
    ],
    'a var_dump' => ['["password"]=> string(7) "hunter2"', '["password"]=> [secret]'],
    'a var_dump over lines' => [
        "array(1) {\n  [\"password\"]=>\n  string(7) \"hunter2\"\n}",
        "array(1) {\n  [\"password\"]=>\n  [secret]\n}",
    ],
    'an authorization key in JSON' => ['"authorization": "Basic dXNlcjpwYXNz"', '"authorization": [secret]'],
    'an authorization key in a PHP array' => ["'Authorization' => 'Basic dXNlcjpwYXNz'", "'Authorization' => [secret]"],
    'an authorization list in JSON' => ['{"headers":{"authorization":["Basic dXNlcjpwYXNz"]}}', '{"headers":{"authorization":[[secret]]}}'],
    'a cookie key in JSON' => ['{"cookie":"laravel_session=abc; XSRF-TOKEN=def"}', '{"cookie":[secret]}'],
    'a password with an escaped quote' => ['{"password":"hun\"ter2"}', '{"password":[secret]}'],
    'a quoted password with spaces' => ['{"password":"hunter 2 with spaces"}', '{"password":[secret]}'],
    'a password with a space' => ['password=hunter two', 'password=[secret] two'],
    'a short password key' => ['pass=hunter2', 'pass=[secret]'],
    'a pw key' => ['pw=hunter2', 'pw=[secret]'],
    'a pin' => ['pin=1234', 'pin=[secret]'],
    'a passphrase' => ['passphrase=correct horse', 'passphrase=[secret] horse'],
    'an auth value' => ['auth=user:pass', 'auth=[secret]'],

    // Fix round 1, M2/M3: a long number before a unit, cards, IBANs, IPv6
    'a ten digit number before seconds' => ['took 1234567890 seconds', 'took [id] seconds'],
    'a ten digit number before ms' => ['took 1234567890 ms', 'took [id] ms'],
    'a ten digit number before KB' => ['id 1234567890 KB', 'id [id] KB'],
    'a card with dots' => ['4111.1111.1111.1111', '[id]'],
    'a card with two spaces' => ['4111  1111  1111  1111', '[id]'],
    'an IBAN in lower case' => ['sa0380000000608010167519', '[id]'],
    'an IBAN in groups in lower case' => ['sa03 8000 0000 6080 1016 7519 from X', '[id] from X'],
    'an IBAN with dashes' => ['SA03-8000-0000-6080-1016-7519', '[id]'],
    'an IPv6 address' => ['Too many requests from 2a02:cb80:4271:93aa:dc3d:1234:5678:9abc', 'Too many requests from [ip]'],
    'an IPv6 address in brackets' => ['connect to [2406:da18:243:7401::1]:5432 failed', 'connect to [[ip]]:5432 failed'],
    'an IPv4 address mapped into IPv6' => ['::ffff:10.0.0.15', '[ip]'],

    // Fix round 1, found while hardening: a secret inside the value of a key we do not mask, text after a failing row, a
    // statement glued to a number, a second statement, a list of values inside brackets
    'a secret after a word and a colon' => ["and: ['password' => 'hunter2']", "and: ['password' => [secret]]"],
    'a secret after a sentence and a colon' => ['Login failed: password=hunter2', 'Login failed: password=[secret]'],
    'a failing row with more text after it' => ['DETAIL:  Failing row contains (1, Fahad, 0501234567). See the log', 'DETAIL:  Failing row contains (?). See the log'],
    'a statement glued to a number' => ['0501234567update "c" set "n" = Fahad', '[phone]update "c" set "n" = ?'],
    'two statements' => ['update "x" set "a" = Fahad; select Noura', 'update "x" set "a" = ?; select ?'],
    'a list of values inside a table expression' => [
        '(Connection: pgsql, SQL: with "t" as (insert into "n" ("b") values (please select Noura from Doha)) select 1)',
        '(Connection: pgsql, SQL: with "t" as (insert into "n" ("b") values (?)) select ?)',
    ],
    'an e-mail address written as an entity' => ['ahmed&#64;example.com', '[email]'],

    // Fix round 1, M6/M7: personal data by its key, Android storage
    'a name and a phone in JSON' => ['{"name":"Fahad Alotaibi","phone":"+966501234567"}', '{"name":[secret],"phone":[secret]}'],
    'a customer name in an assignment' => ['customer_name=Fahad', 'customer_name=[secret]'],
    'a national id in camel case' => ['{"nationalId":"1234567890"}', '{"nationalId":[secret]}'],
    'an address in a PHP array' => ["'address' => 'Street 5, Doha'", "'address' => [secret]"],
    'a full name in a dump' => ['[full_name] => Fahad', '[full_name] => [secret]'],
    // Only an error type is let through: a name written like a class is still a name.
    'a name in JSON written like a class' => ['{"name":"FahadAlotaibi"}', '{"name":[secret]}'],
    'a name in JSON that ends in Error with a space' => ['{"name":"Sara Error"}', '{"name":[secret]}'],
    'an Android storage path' => ['/storage/emulated/0/Download/contract-Fahad-Alotaibi.pdf', '[path]'],
    'an Android card path' => ['Cannot read /sdcard/Download/Fahad.pdf now', 'Cannot read [path] now'],
]);

dataset('errors readable text', [
    'a line number' => 'Error in Foo.php:12',
    'a frame with a line number' => 'at app/Http/Controllers/CustomerController.php(87)',
    'a PHP class name' => 'Illuminate\Database\QueryException',
    'a long PHP class name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
    'another long PHP class name' => 'Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance',
    'a Symfony class name' => 'Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException',
    'a namespaced class name with digits' => 'League\OAuth2\Server\Exception\OAuthServerException',
    'a constant with underscores' => 'OS Error: CERTIFICATE_VERIFY_FAILED: unable to get local issuer certificate',
    'a foreign key name' => 'violates foreign key constraint "contracts_customer_id_foreign"',
    'a key that is not personal data' => 'Cannot load class_name=Foo from the container',
    'a controller action' => 'Target class [App\Http\Controllers\CustomerController@index] does not exist.',
    'a cache key in prose' => 'Cache key: tenant.features.v2',
    'a signature in prose' => 'Invalid signature: Expected',
    'a missing key' => 'Missing key: phone',
    'a size that starts like a mobile number' => 'Read 53687091 bytes',
    'a time that starts like a mobile number' => 'Waited 50000000 ms',
    'a big memory size' => 'Allowed memory size of 1073741824 bytes exhausted',
    'a statement with names that look like personal keys' => 'select * from "customers" where "name" = ? and "phone" = ? and "email" = ?',
    'an update of a personal column' => 'update "customers" set "email" = ? where "id" = ?',
    'a long constraint name with a year' => 'violates unique constraint "contracts_tenant_id_reference_unique_2026_11"',
    'a long path with ids' => 'GET /app/customers/12345/edit/something-long-here failed',
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
    // Hardening B1: a migration's name, and names built with two-letter parts.
    'a migration name' => '2026_10_07_000400_create_customers_table',
    'a migration in a trace frame' => '#3 database/migrations/2026_10_07_000400_create_customers_table.php(23): Illuminate\Database\Migrations\Migration@anonymous->up()',
    'the framework\'s migration of users' => 'Migrating: 0001_01_01_000000_create_users_table',
    'a class with Db in it' => 'Illuminate\Queue\Failed\DynamoDbFailedJobProvider',
    'a class with Qr in it' => 'App\Documents\QrCodeGeneratorForInvoices',
    'a class with Ip in it' => 'App\Http\Middleware\IpAddressAllowListMiddleware',
    'a class with Ui in it' => 'App\View\UiComponentRendererService',
    'a class with Io in it' => 'App\Support\IoExceptionHandlerFactory',
    // Hardening B2: Laravel's pgsql grammar writes "column"::text for every like, and JSON names the error type.
    'a PostgreSQL cast before a like' => 'select * from "customers" where "name"::text ilike ? and "phone"::text ilike ?',
    'an error type in JSON' => '{"name":"TypeError","message":"x is undefined"}',
    'an exception class in JSON' => '{"name":"App\\\\Exceptions\\\\PaymentException","code":0}',
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

    expect($clean)->toBe('insert into "audit_logs" ("id", "metadata") values (?, ?,?)')
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

it('has every connection ask Laravel to keep the bindings of a failed query out of the exception message', function () {
    foreach (config('database.connections') as $name => $connection) {
        expect($connection['mask_bindings_in_exception_messages'] ?? false)->toBeTrue("the {$name} connection must not put bindings into messages");
    }
});

it('has Laravel keep the bindings of a failed query out of the exception message', function () {
    $message = null;

    try {
        DB::select('select * from missing_table where email = ? and phone = ?', ['ahmed@example.com', '0501234567']);
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBeString()
        ->and($message)->not->toContain('ahmed@example.com')
        ->and($message)->not->toContain('0501234567')
        ->and($message)->toContain('where email = ? and phone = ?');
})->skip(
    // With emulated prepares (DB_EMULATE_PREPARES=true: the Vercel container, Supabase's pooler, the PGlite stand-in) the
    // driver sends the statement with the values already put in, and PostgreSQL's own error text echoes the first characters
    // of one (LINE 1: ... email = 'ahmed@exam..). Laravel's masking only covers the "SQL:" part of the message. The error
    // center never stores a database exception's message, so nothing leaks into it; see "Known limits" in error-center.md.
    fn () => DB::connection()->getDriverName() === 'pgsql' && ((DB::connection()->getConfig('options') ?? [])[PDO::ATTR_EMULATE_PREPARES] ?? false) === true,
    'PostgreSQL echoes the substituted statement under emulated prepares; see error-center.md known limits'
);

it('masks the encrypted session cookie in every shape it can arrive in', function () {
    $leaks = [];

    for ($i = 0; $i < 25; $i++) {
        $value = rawurlencode(Crypt::encryptString(Str::random(40)));
        $probe = substr($value, 10, 30);

        foreach ([
            'json' => "{\"laravel_session\":\"{$value}\"}",
            'equals' => "laravel_session={$value}",
            'bare' => "session {$value} expired",
            'header' => "Cookie: laravel_session={$value}",
            'list' => "{\"cookie\":[\"laravel_session={$value}\"]}",
            'remember' => "remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d={$value}",
        ] as $shape => $input) {
            $clean = ErrorScrubber::text($input, 5000);

            if (str_contains($clean, $probe)) {
                $leaks[] = $shape;
            }

            expect(ErrorScrubber::text($clean, 5000))->toBe($clean);
        }
    }

    expect($leaks)->toBe([]);
});

/** A random token the way an application or a service makes one, from a fixed seed so the measure is the same every run. */
function errorsRandomToken(string $family, int $length): string
{
    $base62 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $pick = function (string $alphabet, int $count): string {
        $token = '';
        for ($i = 0; $i < $count; $i++) {
            $token .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }

        return $token;
    };

    return match ($family) {
        'base62' => $pick($base62, $length),
        'base64' => rtrim(base64_encode($pick(implode('', array_map('chr', range(0, 255))), intdiv($length * 3, 4))), '='),
        'base64url' => rtrim(strtr(base64_encode($pick(implode('', array_map('chr', range(0, 255))), intdiv($length * 3, 4))), '+/', '-_'), '='),
        'lower' => strtolower($pick($base62, $length)),
        'upper' => strtoupper($pick($base62, $length)),
    };
}

it('leaves under one in a thousand random tokens in a sentence', function (string $family, int $length) {
    mt_srand(20261104 + $length);
    $samples = 2000;
    $leaked = 0;

    for ($i = 0; $i < $samples; $i++) {
        $token = errorsRandomToken($family, $length);

        if (str_contains(ErrorScrubber::text("value {$token} end"), $token)) {
            $leaked++;
        }
    }

    expect($leaked)->toBeLessThanOrEqual(1, "{$leaked} of {$samples} {$family} tokens of {$length} characters were kept");
})->with([
    'base62 24' => ['base62', 24],
    'base62 32' => ['base62', 32],
    'base62 40' => ['base62', 40],
    'base62 48' => ['base62', 48],
    'base64 24' => ['base64', 24],
    'base64 32' => ['base64', 32],
    'base64 40' => ['base64', 40],
    'base64url 32' => ['base64url', 32],
    'lower case 32' => ['lower', 32],
    'lower case 24' => ['lower', 24],
    'upper case 32' => ['upper', 32],
    'upper case 24' => ['upper', 24],
]);

it('keeps the long names it meets in traces: every migration, and at least 98 % of the framework\'s long class names', function () {
    $names = array_map('basename', glob(database_path('migrations/*.php')));

    foreach ([base_path('vendor/laravel/framework/src'), base_path('vendor/symfony')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $class = basename($file->getFilename(), '.php');

            if (str_ends_with($file->getFilename(), '.php') && strlen($class) >= 24 && preg_match('~^[A-Za-z_]\w*$~D', $class) === 1) {
                $names[] = $class;
            }
        }
    }

    $names = array_values(array_unique($names));
    $failing = array_values(array_filter($names, fn (string $name): bool => ErrorScrubber::text($name) !== $name));
    $rate = 1 - count($failing) / count($names);
    $migrations = array_values(array_filter($failing, fn (string $name): bool => preg_match('~^\d{4}_\d{2}_\d{2}_\d{6}_~', $name) === 1));

    expect(count($names))->toBeGreaterThan(200)
        ->and($migrations)->toBe([], 'migration names masked: '.implode(', ', array_slice($migrations, 0, 10)))
        ->and($rate)->toBeGreaterThanOrEqual(0.98, sprintf('%.2f %% of %d names survive; masked: %s', 100 * $rate, count($names), implode(', ', array_slice($failing, 0, 20))));
});

it('does not cut the middle of a value at the guard against huge texts', function (string $value, string $part) {
    // One long hash becomes one [token] and shrinks the text to a few characters; the guard cuts the raw text at 4096
    // bytes, which is 9 bytes into the value.
    $filler = str_repeat('d41d8cd98f00b204e9800998ecf8427e', 126).' '.str_repeat('x', 53).' ';
    $clean = ErrorScrubber::text($filler.$value.' tail');

    expect(strlen($filler))->toBeLessThan(4096)
        ->and($clean)->not->toContain($part);
})->with([
    'an address' => ['ahmed@example.com', 'ahmed'],
    'a phone number' => ['0501234567', '05012'],
    'a token' => ['kQ9x2LmZp0Vb7TrYw3NcJ8aDh5sFgHjK', 'kQ9x2'],
]);

it('puts a word it does not know into its vocabulary', function () {
    $event = ErrorScrubber::event(errorsEvent(['source' => 'nonsense', 'kind' => 'whatever', 'platform' => 'beos', 'device' => 'television']));

    expect($event->source)->toBe('site')
        ->and($event->kind)->toBe('exception')
        ->and($event->platform)->toBe('server')
        ->and($event->device)->toBeNull();
});

it('keeps every word of the vocabulary', function (string $field, string $word) {
    $event = ErrorScrubber::event(errorsEvent([$field => $word]));

    expect($event->{$field})->toBe($word);
})->with(array_merge(
    array_map(fn (string $word) => ['source', $word], ['site', 'api', 'database', 'app']),
    array_map(fn (string $word) => ['kind', $word], ['exception', 'http', 'slow_query', 'script', 'crash']),
    array_map(fn (string $word) => ['platform', $word], ['server', 'web', 'android', 'ios']),
    array_map(fn (string $word) => ['device', $word], ['desktop', 'mobile', 'tablet']),
));

it('gives a platform made of nothing the default one', function () {
    $event = ErrorScrubber::event(errorsEvent(['platform' => "\x00\u{200B}"]));

    expect($event->platform)->toBe('server');
});

it('accepts a status between 100 and 599 and nothing else', function (int $status, ?int $expected) {
    expect(ErrorScrubber::event(errorsEvent(['status' => $status]))->status)->toBe($expected);
})->with([
    'a huge number' => [99999999999, null],
    'a negative number' => [-1, null],
    'zero' => [0, null],
    'below 100' => [99, null],
    'above 599' => [600, null],
    'the first' => [100, 100],
    'the last' => [599, 599],
    'a usual one' => [404, 404],
]);

it('writes the time as ISO 8601, and as now when it cannot be read', function () {
    $read = ErrorScrubber::event(errorsEvent(['at' => '2026-11-04 10:30:00']))->at;
    $unreadable = ErrorScrubber::event(errorsEvent(['at' => 'not a date']))->at;
    $huge = ErrorScrubber::event(errorsEvent(['at' => str_repeat('x', 100_000)]))->at;

    expect($read)->toBe(CarbonImmutable::parse('2026-11-04 10:30:00')->toIso8601String())
        ->and($unreadable)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
        ->and(CarbonImmutable::parse($unreadable)->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and(strlen($huge))->toBeLessThan(40);
});

it('does not turn text into paths when the project lives in /app, as it does in production', function (string $input, string $expected) {
    $original = app()->basePath();
    app()->setBasePath('/app');

    try {
        $text = ErrorScrubber::text($input);
        $trace = ErrorScrubber::trace($input);
    } finally {
        app()->setBasePath($original);
    }

    expect($text)->toBe($expected)
        ->and($trace)->toBe($expected);
})->with([
    'a route' => ['/app/customers/{id}', '/app/customers/{id}'],
    'a request line' => ['GET /app/customers/{customer} answered 500', 'GET /app/customers/{customer} answered 500'],
    'the app itself' => ['Redirect to /app failed', 'Redirect to /app failed'],
    'a class in quotes' => ['Class "\App\Models\Customer" not found', 'Class "\App\Models\Customer" not found'],
    'a class in brackets' => ['Target [\App\Contracts\Gateway] is not instantiable.', 'Target [\App\Contracts\Gateway] is not instantiable.'],
    'a view' => ['View [app.customers.index] not found.', 'View [app.customers.index] not found.'],
    'an address on the app domain' => ['See https://qistas.app/app/customers for details', 'See https://qistas.app/app/customers for details'],
    'a frame in our code' => ['#0 /app/app/Http/Foo.php(12): Foo->bar()', '#0 app/Http/Foo.php(12): Foo->bar()'],
    'a file in our code' => ['include(/app/app/Foo.php): Failed to open stream', 'include(app/Foo.php): Failed to open stream'],
    'a log file' => ['Unable to read /app/storage/logs/laravel.log', 'Unable to read storage/logs/laravel.log'],
    'a vendor file' => ['#3 /app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): x()', '#3 vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): x()'],
    'a file at the root' => ['Cannot parse /app/composer.json now', 'Cannot parse composer.json now'],
]);

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

it('drops the arguments of a frame even when one of them has a line break', function () {
    $trace = "#0 /var/www/site/web/app/Foo.php(12): Foo->bar('Ahmed\nAli', 'x')\n#1 /var/www/site/web/app/B.php(3): B->c(Array)\n#2 {main}";

    expect(ErrorScrubber::trace($trace))->toBe("#0 app/Foo.php(12): Foo->bar()\n#1 app/B.php(3): B->c()\n#2 {main}");
});

it('cuts the project root whichever slash the path uses', function () {
    $root = base_path();
    $forward = str_replace('\\', '/', $root).'/app/Foo.php';
    $backward = str_replace('/', '\\', $root).'\\app\\Foo.php';

    expect(ErrorScrubber::trace("#0 {$forward}(12): Foo->bar()"))->toBe('#0 app/Foo.php(12): Foo->bar()')
        ->and(ErrorScrubber::text("Cannot read {$forward}: denied"))->toBe('Cannot read app/Foo.php: denied');

    // A backslash is a separator only in a Windows path (on a Unix server it is the namespace of a class).
    if (preg_match('~^[A-Za-z]:~', $root) === 1) {
        expect(ErrorScrubber::trace("#0 {$backward}(12): Foo->bar()"))->toBe('#0 app/Foo.php(12): Foo->bar()');
    }
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
    'a push token with the id of the phone in front' => [
        '/api/v1/push-tokens/dGVzdDp0b2tlbg:APA91bHun4MxP5egoKMwt2KZFBaFUH-1RYqx9z2LmZp0Vb7TrYw3NcJ8aDh5sFgHjK',
        '/api/v1/push-tokens/{id}',
    ],
    'a push token, URL-encoded' => [
        '/api/v1/push-tokens/dGVzdDp0b2tlbg%3AAPA91bHun4MxP5egoKMwt2KZFBaFUH-1RYqx9z2LmZp0Vb7TrYw3NcJ8aDh5sFgHjK',
        '/api/v1/push-tokens/{id}',
    ],
    // What is no plain path once its escapes are read: that segment is {x}, the rest stays.
    'markup in percent escapes' => ['/help/%3Cscript%3E/x', '/help/{x}/x'],
    'markup escaped twice' => ['/help/%253Cb%253E/x', '/help/{x}/x'],
    'escapes still left after three rounds' => ['/help/%25252522/x', '/help/{x}/x'],
    'a quote' => ['/help/what\'s-new', '/help/{x}'],
    'a backtick' => ['/help/a%60b', '/help/{x}'],
    'a backslash' => ['/help/a%5Cb', '/help/{x}'],
    'a space' => ['/help/a%20b/x', '/help/{x}/x'],
    'a line break' => ['/help/a%0Ab', '/help/{x}'],
    'an invisible character' => ['/help/a%E2%80%8Bb', '/help/{x}'],
    'a brace that is no parameter' => ['/help/{a}b', '/help/{x}'],
    'braces in the escapes' => ['/help/%7Bconstructor%7D.x', '/help/{x}'],
    'bytes that are not UTF-8' => ['/help/%C3%28', '/help/{x}'],
    'an Arabic word, escaped' => ['/ar/%D8%A3%D8%B3%D8%B9%D8%A7%D8%B1', '/ar/أسعار'],
    'a percent sign that escapes nothing' => ['/sale/100%25', '/sale/100%'],
    'a question mark written as an escape is a query string' => ['/help/topic%3Fq=%3Cb%3E', '/help/topic'],
]);

it('a route scrubbed once is scrubbed for good: scrubbing it again changes nothing', function (string $route) {
    $once = ErrorScrubber::route($route);

    expect(ErrorScrubber::route($once))->toBe($once);
})->with([
    '/%3Cscript%3Ealert(1)%3C/script%3E', '/%253Cscript%253E', '/a/%25252522x', '/ar/%D8%A3%D8%B3%D8%B9%D8%A7%D8%B1',
    '/sale/100%25', '/app/customers/{customer?}', '/x/%2541%2542', '/p/%2B966501234567', '/help/{x}/x', '/a%2Fb%2F%3C',
]);

it('clamps every field of an event to the width of its column, after masking', function () {
    $event = ErrorScrubber::event(errorsEvent([
        'class' => str_repeat('Z', 300),
        'message' => str_repeat('m', 900),
        'trace' => str_repeat("#0 app/Foo.php(12): Foo->bar()\n", 600),
        'method' => 'PROPPATCH',
        'route' => '/app/'.str_repeat('r', 300),
        'version' => str_repeat('v', 40),
    ]));

    expect($event->class)->toHaveLength(160)
        ->and(mb_strlen($event->message))->toBe(500)
        ->and(strlen($event->trace))->toBeLessThanOrEqual(8192)
        ->and($event->method)->toBe('PROPPATC')
        ->and($event->route)->toHaveLength(160)
        ->and($event->version)->toHaveLength(16);
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
