<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\SignInPresenter;
use App\Http\Resources\SignInEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Recent sign-ins": the last 20 times someone signed in to the signed-in person's account, or got its password wrong,
 * newest first (go-live basics, spec section 5). Only ever the person's own, read through their own relation.
 */
final class SignInsController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return SignInEventResource::collection($request->user()->signInEvents()->limit(SignInPresenter::PERSON_LIMIT)->get());
    }
}
