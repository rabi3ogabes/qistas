<?php

namespace App\Http\Controllers\Workspace;

use App\Account\ProfilePhoto;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Locale;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * "Your profile": the signed-in person's own details (name, e-mail, phone, language), photo and password, in the web
 * app. The same actions serve the app (Api\V1\ProfileController) and Fortify's own routes.
 */
final class ProfileController
{
    public function show(Request $request, CurrentTenant $current): View
    {
        $user = $this->user($request);
        $tenant = $current->get();

        return view('app.profile', [
            'user' => $user,
            'photo' => $user->photo,
            'role' => $tenant === null ? null : $user->roleIn($tenant->id),
            'languages' => Locale::options(),
        ]);
    }

    public function update(Request $request, UpdateUserProfileInformation $update): RedirectResponse
    {
        $user = $this->user($request);
        $wasEmail = $user->email;
        $update->update($user, $request->only(['name', 'email', 'phone', 'locale']));

        // A language chosen here is the one the pages read in from now on, on this browser too.
        if ($request->filled('locale')) {
            $request->session()->put('locale', $user->locale);
            Cookie::queue(SetLocale::COOKIE, $user->locale, 60 * 24 * 365);
        }

        return redirect()->route('app.profile')->with('status', $user->email !== $wasEmail
            ? __('Your details are saved. We sent a link to your new e-mail to confirm it.')
            : __('Your details are saved.'));
    }

    public function password(Request $request, UpdateUserPassword $update): RedirectResponse
    {
        $update->update($this->user($request), $request->only(['current_password', 'password', 'password_confirmation']));

        return redirect()->route('app.profile')->with('status', __('Your password is changed.'));
    }

    public function photo(Request $request): Response
    {
        $photo = $this->user($request)->photo ?? abort(404);

        return response($photo->bytes(), 200, [
            'Content-Type' => $photo->mime,
            // Private to the person; the address carries the picture's fingerprint, so a new photo is a new address.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storePhoto(Request $request, ProfilePhoto $photos): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file']]);
        $photos->store($this->user($request), $request->file('photo'));

        return redirect()->route('app.profile')->with('status', __('Your photo is saved.'));
    }

    public function destroyPhoto(Request $request, ProfilePhoto $photos): RedirectResponse
    {
        $photos->remove($this->user($request));

        return redirect()->route('app.profile')->with('status', __('Your photo is removed.'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
