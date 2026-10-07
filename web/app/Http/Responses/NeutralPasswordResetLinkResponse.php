<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * The answer to "send me a reset link" is the same whether or not the email belongs to an account (and
 * whether or not the broker throttled it), so the form cannot be used to find out who has an account.
 */
final class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    // Fortify passes the broker's status here; it is deliberately ignored.
    public function toResponse($request)
    {
        $message = __('If an account exists for that email, we have sent a password reset link.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
