<?php

declare(strict_types=1);

namespace App\Errors;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * A failure of the database, as the error center keeps it (design sections 3 and 6). The database's own message holds the
 * values of the row it refused (`Key (email)=(sara@example.com) already exists`) and Laravel's adds the bindings, so the
 * error center never keeps either: what it says is built from what the failure IS, the SQLSTATE and its meaning, and for a
 * failed query the connection's name and the statement with its placeholders. Pure: no database, no container.
 */
final class DatabaseFailure
{
    /** What a SQLSTATE means, in the words an issue's title uses; every code of class 08 is a lost connection. */
    public const DESCRIPTIONS = [
        '23505' => 'Unique violation',
        '23503' => 'Foreign key violation',
        '23502' => 'Not-null violation',
        '22001' => 'Value too long',
        '42P01' => 'Undefined table',
        '42703' => 'Undefined column',
        '57014' => 'Query cancelled',
        '53300' => 'Too many connections',
        '40P01' => 'Deadlock',
        '40001' => 'Serialization failure',
    ];

    public const LOST_CONNECTION = 'Lost connection to the database';

    /** Exceptions looked through, the given one and those it wraps. */
    private const CHAIN = 8;

    /**
     * The database failure in $e or in what it wraps (a view that failed on a query wraps the QueryException): a failed
     * query or a PDO error first, then anything whose message is the database's (it carries a SQLSTATE, or PDO says the
     * connection was lost or a lock was refused, as a PDO warning turned into an ErrorException does). Null when there is
     * none: "Connection reset by peer" from another server is not one.
     */
    public static function in(Throwable $e): ?Throwable
    {
        $chain = [];

        for ($link = $e; $link !== null && count($chain) < self::CHAIN; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        foreach ($chain as $link) {
            if ($link instanceof PDOException || $link instanceof LostConnectionException) {
                return $link; // a QueryException is a PDOException
            }
        }

        foreach ($chain as $link) {
            $message = $link->getMessage();

            if (str_contains($message, 'SQLSTATE[')
                || (str_starts_with($message, 'PDO') && (self::lostConnection($link) || self::deadlock($link)))) {
                return $link;
            }
        }

        return null;
    }

    /**
     * What is kept of a database failure: `SQLSTATE[23505] Unique violation (Connection: pgsql, SQL: insert into ...)`, in
     * the shape Laravel writes, so the scrubber reads the statement as SQL. A code with no words is the code alone; a
     * failure with no code says what it was when that is known.
     */
    public static function message(Throwable $failure): string
    {
        $state = self::sqlState($failure);
        $parts = $state === null ? [] : ['SQLSTATE['.$state.']'];
        $description = self::description($state, $failure);

        if ($description !== null) {
            $parts[] = $description;
        }

        if ($failure instanceof QueryException) {
            $parts[] = '(Connection: '.$failure->getConnectionName().', SQL: '.$failure->getSql().')';
        }

        return $parts === [] ? 'Database failure' : implode(' ', $parts);
    }

    /** The five characters of the SQLSTATE: from the driver's error info or code, or read out of the message. */
    public static function sqlState(Throwable $failure): ?string
    {
        $info = $failure instanceof PDOException ? $failure->errorInfo : null;

        foreach ([is_array($info) ? ($info[0] ?? null) : null, $failure->getCode()] as $code) {
            if (is_string($code) && preg_match('~^[0-9A-Z]{5}$~D', $code) === 1) {
                return $code;
            }
        }

        return preg_match('~SQLSTATE\[([0-9A-Z]{5})\]~', $failure->getMessage(), $found) === 1 ? $found[1] : null;
    }

    /** The words for a failure: those of its SQLSTATE, else what Laravel's detectors make of it. */
    private static function description(?string $state, Throwable $failure): ?string
    {
        if ($state !== null && str_starts_with($state, '08')) {
            return self::LOST_CONNECTION;
        }

        if ($state !== null && isset(self::DESCRIPTIONS[$state])) {
            return self::DESCRIPTIONS[$state];
        }

        if (self::lostConnection($failure)) {
            return self::LOST_CONNECTION;
        }

        return self::deadlock($failure) ? 'Deadlock or lock timeout' : null;
    }

    private static function lostConnection(Throwable $e): bool
    {
        return $e instanceof LostConnectionException || (new LostConnectionDetector)->causedByLostConnection($e);
    }

    private static function deadlock(Throwable $e): bool
    {
        return (new ConcurrencyErrorDetector)->causedByConcurrencyError($e);
    }
}
