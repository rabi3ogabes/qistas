<?php

namespace App\Http\Responses;

use App\Auth\SignInLog;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Http\Responses\RegisterResponse as FortifyRegisterResponse;

/**
 * Fortify's answer to a new account from the website's sign-up form, which also signs the new owner in. Plus one thing:
 * the sign-in record, written once here (the sign-in on the way is part of the sign-up). The answer is Fortify's own.
 */
final class RecordsRegistration implements RegisterResponse
{
    public function __construct(private readonly SignInLog $signIns) {}

    /** @param  Request  $request */
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->signIns->success($user, $request);
        }

        return (new FortifyRegisterResponse)->toResponse($request);
    }
}
