<?php

namespace App\Http\Responses;

use App\Auth\SignInLog;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;

/**
 * Fortify's answer to a password sign-in that is complete at the password: no two-step sign-in, or a browser trusted to
 * skip the code (App\Auth\TrustedDevice). Plus one thing: the sign-in record. A sign-in that stops at the code is
 * recorded when the code is right (RemembersTrustedDevice). The answer itself is Fortify's own, unchanged.
 */
final class RecordsSignIn implements LoginResponse
{
    public function __construct(private readonly SignInLog $signIns) {}

    /** @param  Request  $request */
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->signIns->success($user, $request);
        }

        return (new FortifyLoginResponse)->toResponse($request);
    }
}
