<?php

use App\Errors\SqlMasker;

/*
| Laravel puts the bindings of a failed query into the exception message without quotes, so a value cannot be told
| from the statement around it by its quotes. SqlMasker keeps the structure (keywords, quoted names, `?`) and loses
| every value; once a value starts, everything up to the next comma, closing bracket or quoted name is value, whatever
| words it contains.
*/

dataset('errors sql statements', [
    // What the reviewer found: words that sit next to keywords, dots, operators and line breaks inside a value
    'a note with from inside' => [
        'update "customers" set "notes" = Payment from Ahmed Ali Alghamdi in Riyadh, "phone" = 0501234567 where "id" = 5',
        'update "customers" set "notes" = ?, "phone" = ? where "id" = ?',
    ],
    'a note with select inside' => [
        'insert into "notes" ("body", "tenant_id") values (please select Fahad Alotaibi for the visit, 7)',
        'insert into "notes" ("body", "tenant_id") values (?, ?)',
    ],
    'a note with into inside' => [
        'insert into "notes" ("body") values (moved into Villa Fahad)',
        'insert into "notes" ("body") values (?)',
    ],
    'a note with order by inside' => [
        'insert into "notes" ("body") values (order by Fahad Alotaibi)',
        'insert into "notes" ("body") values (?)',
    ],
    'a note with by inside' => [
        'insert into "notes" ("body") values (sent by Fahad Alotaibi)',
        'insert into "notes" ("body") values (?)',
    ],
    'a note with as inside' => [
        'update "customers" set "notes" = known as Abu Fahad, "phone" = 0501234567 where "id" = 5',
        'update "customers" set "notes" = ?, "phone" = ? where "id" = ?',
    ],
    'a note with is inside' => [
        'update "customers" set "notes" = Fahad is late again where "id" = 5',
        'update "customers" set "notes" = ? where "id" = ?',
    ],
    'a name with a title' => [
        'update "customers" set "name" = Mr. Fahad Alotaibi where "id" = 5',
        'update "customers" set "name" = ? where "id" = ?',
    ],
    'a name with another title in a list' => [
        'insert into "customers" ("name", "phone") values (Dr. Noura Alsubaie, 0501234567)',
        'insert into "customers" ("name", "phone") values (?, ?)',
    ],
    'a name with an address in angle brackets' => [
        'update "users" set "name" = Fahad Alotaibi <fahad@example.com> where "id" = 5',
        'update "users" set "name" = ? where "id" = ?',
    ],
    'a note with an arrow' => [
        'insert into "notes" ("body") values (Fahad => paid 500)',
        'insert into "notes" ("body") values (?)',
    ],
    'a name in capitals with from' => [
        'update "customers" set "name" = FAHAD FROM DOHA where "id" = 5',
        'update "customers" set "name" = ? where "id" = ?',
    ],
    'a name in lower case' => [
        'update "customers" set "name" = fahad alotaibi where "id" = 5',
        'update "customers" set "name" = ? where "id" = ?',
    ],
    'an address with a line break' => [
        "insert into \"customers\" (\"address\", \"name\") values (Street 5\nFahad Alotaibi Villa 12, null)",
        'insert into "customers" ("address", "name") values (?, ?)',
    ],
    'a case expression' => [
        'select case when "name" = Fahad then 1 else 0 end from "x"',
        'select case when "name" = ? end from "x"',
    ],
    'a list of names' => [
        'select * from "customers" where "name" in (Fahad, Noura, Salem)',
        'select * from "customers" where "name" in (?, ?, ?)',
    ],
    'a like with a name' => [
        'select * from "customers" where "name"::text ilike %Fahad Alotaibi%',
        'select * from "customers" where "name"::text ilike ?',
    ],
    'a name after a json operator' => [
        'select * from "customers" where "meta"->>\'name\' = Fahad',
        'select * from "customers" where "meta"->>?',
    ],
    'a range of dates' => [
        'select * from "payments" where "paid_at" between 2026-01-01 00:00:00 and 2026-02-01 00:00:00',
        'select * from "payments" where "paid_at" between ?',
    ],
    'a value that ends where a name starts' => [
        'select count(*) as aggregate from "customers" where "tenant_id" = 7 and "name" = Fahad',
        'select count(*) as aggregate from "customers" where "tenant_id" = ? and "name" = ?',
    ],
    'a returning clause' => [
        'insert into "customers" ("name") values (Fahad) returning "id"',
        'insert into "customers" ("name") values (?) returning "id"',
    ],
    'a name with a quote in it' => [
        'update "customers" set "name" = O\'Brien, "phone" = 0501234567 where "id" = 5 returning "id"',
        'update "customers" set "name" = ?',
    ],
    'an escaped quote in an E string' => [
        "select * from \"x\" where \"a\" = E'Fahad\\'s'",
        'select * from "x" where "a" = ?',
    ],
    'a statement followed by a sentence' => [
        'Query select * from "x" where "y" = Fahad failed. Customer Noura reported it.',
        'Query select * from "x" where "y" = ?',
    ],
    'a second statement behind a semicolon' => [
        'update "x" set "a" = Fahad; drop table "y"; select Noura',
        'update "x" set "a" = ?; drop table "y"; select ?',
    ],
    'a not like with a name' => [
        'select * from "x" where "a" = Fahad not like it',
        'select * from "x" where "a" = ?',
    ],
    'a name in a document inside a list' => [
        'insert into "audit_logs" ("id", "metadata") values (1, {"name":"Ahmed","phone":"0501234567"})',
        'insert into "audit_logs" ("id", "metadata") values (?, ?,?)',
    ],

    'a list of values inside a table expression' => [
        'with "t" as (insert into "n" ("b") values (please select Noura from Doha)) select 1',
        'with "t" as (insert into "n" ("b") values (?)) select ?',
    ],
    'two rows of values' => [
        'insert into "n" ("a", "b") values (Fahad Alotaibi, 1), (Noura from Doha, 2)',
        'insert into "n" ("a", "b") values (?, ?), (?, ?)',
    ],
    'a mask from an earlier pass' => [
        'update "x" set "a" = ?; "password":[secret]',
        'update "x" set "a" = ?; "password":[secret]',
    ],

    // Raw statements with names that are not quoted: a name is kept only in the lists of columns and tables
    'a raw statement without quotes' => [
        'select * from customers where name = Fahad Alotaibi and phone = 0501234567',
        'select * from customers where ? = ?',
    ],
    'a raw statement with a list' => [
        'select * from customers where name in (Fahad) and city = Doha',
        'select * from customers where ? in (?) and ? = ?',
    ],
    'a raw aggregate' => [
        'select id, name from customers c order by name desc, id limit 5',
        'select id, name from customers c order by name desc, id limit ?',
    ],
    'a raw insert' => [
        'insert into customers (name, phone) values (Fahad, 0501234567)',
        'insert into customers (name, phone) values (?, ?)',
    ],

    // Statements that only have placeholders keep every bit of their shape
    'placeholders' => [
        'select * from "customers" where "tenant_id" = ? and "id" in (?, ?) order by "created_at" desc limit 50 offset 100',
        'select * from "customers" where "tenant_id" = ? and "id" in (?, ?) order by "created_at" desc limit ? offset ?',
    ],
    'numbered placeholders' => [
        'select * from "x" where "a" = $1 and "b" = $2',
        'select * from "x" where "a" = $1 and "b" = $2',
    ],
    'a range with placeholders' => [
        'select * from "x" where "a" between ? and ? and "b" is null',
        'select * from "x" where "a" between ? and ? and "b" is null',
    ],
    'a join on two columns' => [
        'select "c"."id" from "contracts" as "c" inner join "customers" on "customers"."id" = "c"."customer_id" where "c"."note" = ?',
        'select "c"."id" from "contracts" as "c" inner join "customers" on "customers"."id" = "c"."customer_id" where "c"."note" = ?',
    ],
    'a sub-select' => [
        'select * from "customers" where "id" in (select "customer_id" from "contracts" where "status" = late) and "name" = Ahmed Ali order by "id" desc',
        'select * from "customers" where "id" in (select "customer_id" from "contracts" where "status" = ?) and "name" = ? order by "id" desc',
    ],
    'a function around a column' => [
        'select * from "customers" where lower("name") like %ahmed ali% and "created_at" >= 2026-11-04 10:30:00',
        'select * from "customers" where lower("name") like ? and "created_at" >= ?',
    ],
    'a statement after the connection details' => [
        '(Connection: pgsql, SQL: values (Fahad Alotaibi))',
        '(Connection: pgsql, SQL: values (?))',
    ],
    'a statement that is not one we know' => [
        '(Connection: pgsql, SQL: copy "customers" from Fahad Alotaibi)',
        '(Connection: pgsql, SQL: ? "customers" from ?)',
    ],
]);

it('keeps the structure of a statement and loses every value', function (string $input, string $expected) {
    expect(SqlMasker::mask($input))->toBe($expected);
})->with('errors sql statements');

it('masks a statement once, however many times it is read', function (string $input) {
    $once = SqlMasker::mask($input);

    expect(SqlMasker::mask($once))->toBe($once);
})->with('errors sql statements');

it('leaves a text without a statement alone', function (string $text) {
    expect(SqlMasker::mask($text))->toBe($text);
})->with([
    'a sentence' => 'Please select an item from the list',
    'a message' => 'Customer Fahad Alotaibi not found',
    'an update notice' => 'Update failed: from version 1.29.0 to 1.30.0',
    'nothing' => '',
]);

it('knows where a statement starts', function (string $text, bool $is) {
    expect(SqlMasker::looksLikeStatement($text))->toBe($is);
})->with([
    'a select' => ['select * from "x"', true],
    'a select of a case' => ['select case when "a" = ? then 1 end from "x"', true],
    'a select of a name' => ['select "id" from "x"', true],
    'a select of columns' => ['select id, name from x', true],
    'a count' => ['select count(*) from "x"', true],
    'an insert' => ['insert into "x" ("a") values (?)', true],
    'an update' => ['update "x" set "a" = ?', true],
    'a delete' => ['delete from "x" where "id" = ?', true],
    'a table expression' => ['with "t" as (select 1) select * from "t"', true],
    'a statement after the connection details' => ['(Connection: pgsql, SQL: alter table "x" add "y" int)', true],
    'a statement in upper case' => ['SELECT * FROM "x"', true],
    'a sentence' => ['Please select an item from the list', false],
    'a message' => ['Customer not found', false],
    'a word' => ['update', false],
]);

it('lets nothing of a value through, whatever shape the statement has', function (string $statement) {
    $masked = SqlMasker::mask($statement);

    foreach (['Fahad', 'Noura', 'Alotaibi', 'Alsubaie', 'Riyadh', 'Doha', 'Salem', 'Ahmed', '0501234567'] as $value) {
        expect($masked)->not->toContain($value);
    }
})->with([
    'a paren inside a value' => '(Connection: pgsql, SQL: insert into "customers" ("name", "phone") values (Fahad) Alotaibi, 0501234567))',
    'a quote inside a value' => 'update "c" set "n" = Fahad\'s Noura, "m" = Doha where "id" = 5',
    'a value after a keyword' => 'select * from "c" where "n" like Fahad Alotaibi',
    'a value after a comparison' => 'select * from "c" where "n" >= Fahad and "m" <> Noura',
    'a value after an operator in a list' => 'insert into "c" ("a", "b") values (Fahad || Noura, Alotaibi)',
    'a value after then' => 'select case when "a" = ? then Fahad else Noura end from "c"',
    'a value in a function' => 'select * from "c" where lower(Fahad) = lower(Noura)',
    'a value in a set' => 'update "c" set ("a", "b") = (Fahad, Noura) where "id" = Doha',
    'a value that looks like a name in quotes' => 'update "c" set "n" = Fahad "Abu Salem" Alotaibi where "id" = 5',
    'a name in a select list' => 'select Fahad, Noura',
    'an unterminated string' => "select * from \"c\" where \"n\" = 'Fahad Alotaibi",
    'a value with a backslash' => "select * from \"c\" where \"n\" = 'Fahad\\' Noura, 'Doha'",
    'a line break after the value' => "select * from \"c\" where \"n\" = Fahad\nAlotaibi\nand \"m\" = Noura",
    'a comment' => 'select * from "c" where "n" = Fahad -- Noura',
]);

it('works through a long text of statements in a moment', function () {
    $text = str_repeat("select a, b from x where y = Fahad Alotaibi\n", 3000);

    $started = microtime(true);
    $masked = SqlMasker::mask($text);

    // About a fifth of a second on a quiet machine. The bound is generous on purpose (a loaded CI runner, or a folder of
    // tests running beside this one, has been seen to take a few times as long): a regression is a different order of magnitude.
    expect(microtime(true) - $started)->toBeLessThan(6.0)
        ->and($masked)->not->toContain('Fahad');
});

it('gives up, never lets the text through, when a pattern cannot read it', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '5');

    try {
        $thrown = null;

        try {
            SqlMasker::mask(str_repeat('select a, b from x where y = Fahad Alotaibi, ', 50));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', (string) $jit);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);
});
