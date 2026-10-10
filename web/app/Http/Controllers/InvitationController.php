<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Actions\Team\AcceptInvitation;
use App\Http\ApiException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The page an invitation link opens. It says which business and which role; someone signed in joins with one tap,
 * someone new makes a login right there (no business of their own is created), and anyone else signs in first and
 * comes back. A used, expired or revoked link says so plainly.
 */
final class InvitationController
{
    use PasswordValidationRules;

    public function show(string $token): View
    {
        $invitation = AcceptInvitation::find($token);

        return view('site.invitation', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        try {
            $tenant = $accept->handle($token, $request->user());
        } catch (ApiException $e) {
            return redirect()->route('invitation.show', ['token' => $token])->with('error', $e->getMessage());
        }

        return redirect()->route('app.dashboard')->with('status', __('Welcome to :name.', ['name' => $tenant->name]));
    }

    public function register(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        abort_if(AcceptInvitation::find($token) === null, 410);

        $input = $request->all();
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ])->validate();

        $user = DB::transaction(function () use ($input, $token, $accept): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'locale' => app()->getLocale(),
            ]);
            Audit::record('account.registered_by_invitation', $user, userId: $user->id);
            $accept->handle($token, $user);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('app.dashboard');
    }
}
