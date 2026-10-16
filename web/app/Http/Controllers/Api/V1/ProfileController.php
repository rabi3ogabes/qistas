<?php

namespace App\Http\Controllers\Api\V1;

use App\Account\ProfilePhoto;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Api\AccountPayload;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in person's own profile in the app: details, password and photo. Each answer that changes the profile is
 * the same object as GET /me, so the app redraws from it. The same actions serve the web profile page.
 */
final class ProfileController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function update(Request $request, UpdateUserProfileInformation $update): JsonResponse
    {
        $update->update($this->user($request), $request->only(['name', 'email', 'phone', 'locale']));

        return $this->me($request);
    }

    public function password(Request $request, UpdateUserPassword $update): Response
    {
        $update->update($this->user($request), $request->only(['current_password', 'password', 'password_confirmation']));

        return response()->noContent();
    }

    public function storePhoto(Request $request, ProfilePhoto $photos): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file']]);
        $photos->store($this->user($request), $request->file('photo'));

        return $this->me($request);
    }

    public function destroyPhoto(Request $request, ProfilePhoto $photos): JsonResponse
    {
        $photos->remove($this->user($request));

        return $this->me($request);
    }

    private function me(Request $request): JsonResponse
    {
        $user = $this->user($request)->refresh();

        return response()->json(['data' => AccountPayload::for($user, $this->current->get() ?? abort(403))]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
