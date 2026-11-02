<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignInEventResource;
use App\Models\SignInEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Recent sign-ins": the signed-in person's latest sign-ins to their own account, newest first, up to 20 (go-live
 * basics, spec section 5). At most the 5 newest wrong passwords are among them and real sign-ins fill the rest, so a
 * stranger's guesses cannot hide a real sign-in (SignInEvent::recent). Only ever the person's own.
 */
final class SignInsController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return SignInEventResource::collection(SignInEvent::recentFor($request->user()));
    }
}
