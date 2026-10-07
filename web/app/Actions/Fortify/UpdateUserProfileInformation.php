<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'locale' => ['nullable', 'string', Rule::in(config('qistas.locales'))],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = $input['email'] !== $user->email;

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'locale' => $input['locale'] ?? $user->locale,
            // A new address has not been proven yet.
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }
    }
}
