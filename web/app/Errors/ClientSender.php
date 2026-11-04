<?php

declare(strict_types=1);

namespace App\Errors;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Who a report from a browser or a phone counts against (design section 9): for the request limit of the phone's endpoint
 * and for the share of new issues one sender may open (ErrorRecorder::SENDER_NEW_ISSUES). It is a key and never a name: the
 * phone's token number, or the address. Nothing here is stored; the recorder turns the key into a keyed hash for a cache key.
 *
 * Only a token made for the phone app (its ability `app`) is a phone. A token made to connect another program to a
 * workspace (ability `integration`) is not: it is no person here, and its reports count against its address like any
 * stranger's. A made-up, expired or revoked token is nobody either, and so is a token store that fails.
 */
final class ClientSender
{
    /** The person on the request's app token, or null. */
    public static function person(Request $request): ?User
    {
        try {
            $user = $request->user('sanctum');

            return $user instanceof User && $user->tokenCan('app') ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** `token:<number>` for a phone with a token of its own, else `address:<address>`. */
    public static function key(Request $request): string
    {
        $user = self::person($request);

        if ($user !== null) {
            try {
                // The token's own number: one person with two phones has two. (A token that has none, such as the one
                // Sanctum::actingAs makes for a test, throws here and is counted against the address below.)
                return 'token:'.$user->currentAccessToken()->getKey();
            } catch (Throwable) {
                // counted against the address below
            }
        }

        return self::address($request);
    }

    /** `address:<address>`: a browser's report, or a phone's without a token of its own. */
    public static function address(Request $request): string
    {
        return 'address:'.$request->ip();
    }
}
