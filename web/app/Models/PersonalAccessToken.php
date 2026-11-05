<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token, with one rule added: a bearer token is `<id>|<secret>`, the id is the number of a row of
 * personal_access_tokens (a bigint), so a token whose id is not a whole number from 1 to 9223372036854775807 is no token
 * at all and is never looked up. Sanctum's guard already refuses an id that is not made of digits, but it asks the
 * database for any run of digits, and PostgreSQL refuses a number a bigint cannot hold (SQLSTATE 22003): a stranger's
 * header `Bearer 99999999999999999999|x` was a 500 on every route behind the sign-in, and a database failure in the error
 * center. Registered in AppServiceProvider (Sanctum::usePersonalAccessTokenModel). Not final: Sanctum::actingAs mocks it.
 */
class PersonalAccessToken extends SanctumToken
{
    /** The largest id a bigint holds. */
    public const MAX_ID = '9223372036854775807';

    /**
     * The token for this plain text, or null. A token with an id that no row can have is null without a query.
     *
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (str_contains($token, '|') && ! self::isId(explode('|', $token, 2)[0])) {
            return null;
        }

        return parent::findToken($token);
    }

    /** Can this be the id of a row: digits, no leading zero, from 1 up to the largest bigint? */
    public static function isId(string $id): bool
    {
        if (preg_match('~^[1-9][0-9]{0,18}$~D', $id) !== 1) {
            return false;
        }

        return strlen($id) < strlen(self::MAX_ID) || strcmp($id, self::MAX_ID) <= 0;
    }
}
