<?php

use App\Errors\DatabaseFailure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;

/*
| What the error center makes of a failure of the database (design sections 3 and 6): which exception in a chain is the
| database's, and what is said about it, built from what it IS (the SQLSTATE and its meaning, the statement with its
| placeholders) and never from its message, which carries the values.
*/

/** A PDO error with this SQLSTATE and the database's own words, which name a person. */
function errorsDatabasePdo(string $state, string $words = 'Key (email)=(sara.ali@example.com) already exists.'): PDOException
{
    $pdo = new PDOException("SQLSTATE[{$state}]: {$words}");
    $pdo->errorInfo = [$state, 7, $words];

    return $pdo;
}

function errorsDatabaseQuery(string $state = '23505'): QueryException
{
    return new QueryException('pgsql', 'select * from "customers" where "email" = ?', ['sara.ali@example.com'], errorsDatabasePdo($state));
}

it('names the common SQLSTATE codes, and every code of class 08 as a lost connection', function (string $state, string $words) {
    expect(DatabaseFailure::message(errorsDatabasePdo($state, 'anything')))->toBe("SQLSTATE[{$state}] {$words}");
})->with([
    ['23505', 'Unique violation'],
    ['23503', 'Foreign key violation'],
    ['23502', 'Not-null violation'],
    ['22001', 'Value too long'],
    ['42P01', 'Undefined table'],
    ['42703', 'Undefined column'],
    ['57014', 'Query cancelled'],
    ['53300', 'Too many connections'],
    ['40P01', 'Deadlock'],
    ['40001', 'Serialization failure'],
    ['08006', 'Lost connection to the database'],
    ['08001', 'Lost connection to the database'],
    ['08P01', 'Lost connection to the database'],
]);

it('a failed query says its SQLSTATE, what it means, its connection and its statement, and none of its values', function () {
    expect(DatabaseFailure::message(errorsDatabaseQuery()))
        ->toBe('SQLSTATE[23505] Unique violation (Connection: pgsql, SQL: select * from "customers" where "email" = ?)');
});

it('a code it has no words for is the code alone, and a failure with no code at all still says what it was', function () {
    expect(DatabaseFailure::message(errorsDatabasePdo('HY000', 'general error for sara.ali@example.com')))->toBe('SQLSTATE[HY000]')
        ->and(DatabaseFailure::message(new LostConnectionException('Lost connection and no reconnector available.')))->toBe('Lost connection to the database')
        ->and(DatabaseFailure::message(new ErrorException('PDO::prepare(): Send of 1024 bytes failed with errno=32 Broken pipe')))->toBe('Lost connection to the database')
        ->and(DatabaseFailure::message(new RuntimeException('PDOStatement::execute(): Deadlock found when trying to get lock')))->toBe('Deadlock or lock timeout')
        ->and(DatabaseFailure::message(new RuntimeException('PDO::exec(): something')))->toBe('Database failure');
});

it('reads the SQLSTATE from the error info, the code or the message, never anything else of the message', function () {
    $coded = new PDOException('no state in these words');
    $coded->errorInfo = null;
    $reflection = new ReflectionProperty(Exception::class, 'code');
    $reflection->setValue($coded, '42P01');

    expect(DatabaseFailure::sqlState(errorsDatabasePdo('23503')))->toBe('23503')
        ->and(DatabaseFailure::sqlState($coded))->toBe('42P01')
        ->and(DatabaseFailure::sqlState(new RuntimeException('Saving failed: SQLSTATE[22001]: value too long')))->toBe('22001')
        ->and(DatabaseFailure::sqlState(new RuntimeException('SQLSTATE[x] is not one')))->toBeNull()
        ->and(DatabaseFailure::sqlState(new RuntimeException('no code')))->toBeNull();
});

it('finds the database failure in a chain, a typed one before one known only by its message', function () {
    $query = errorsDatabaseQuery();

    expect(DatabaseFailure::in($query))->toBe($query)
        ->and(DatabaseFailure::in(new RuntimeException('The page could not be drawn', 0, $query)))->toBe($query)
        ->and(DatabaseFailure::in(new RuntimeException('a view of SQLSTATE[23505] words', 0, $query)))->toBe($query) // the typed one wins
        ->and(DatabaseFailure::in($deadlock = new DeadlockException('SQLSTATE[40P01]: deadlock detected')))->toBe($deadlock)
        ->and(DatabaseFailure::in($lost = new LostConnectionException('Lost connection and no reconnector available.')))->toBe($lost);

    $wrapper = new RuntimeException('Saving failed: SQLSTATE[23505]: Unique violation: Key (email)=(sara.ali@example.com) already exists.');
    $warning = new ErrorException('PDO::prepare(): Send of 1024 bytes failed with errno=32 Broken pipe');

    expect(DatabaseFailure::in($wrapper))->toBe($wrapper)
        ->and(DatabaseFailure::message($wrapper))->toBe('SQLSTATE[23505] Unique violation')
        ->and(DatabaseFailure::in($warning))->toBe($warning);
});

it('is not fooled by a failure of something else that says "connection"', function () {
    expect(DatabaseFailure::in(new ConnectionException('cURL error 56: Recv failure: Connection reset by peer')))->toBeNull()
        ->and(DatabaseFailure::in(new RuntimeException('The mail server has gone away')))->toBeNull()
        ->and(DatabaseFailure::in(new RuntimeException('Deadlock found when trying to get lock')))->toBeNull() // no PDO, no SQLSTATE
        ->and(DatabaseFailure::in(new RuntimeException('PDO is not a word we look for alone')))->toBeNull();
});

it('looks no deeper than eight exceptions', function () {
    $chain = errorsDatabaseQuery();

    foreach (range(1, 8) as $n) {
        $chain = new RuntimeException("Layer {$n}", 0, $chain);
    }

    expect(DatabaseFailure::in($chain))->toBeNull()
        ->and(DatabaseFailure::in($chain->getPrevious()))->not->toBeNull();
});
