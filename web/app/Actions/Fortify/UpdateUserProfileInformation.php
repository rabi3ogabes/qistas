<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\Audit;
use App\Support\Digits;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * A person's own details: name, e-mail, phone and language, from the profile page, the app or Fortify's route. A field
 * that is not sent stays as it is; a new e-mail must be verified again. The activity log notes which fields changed,
 * never their values.
 */
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
        $input['name'] = trim((string) ($input['name'] ?? ''));
        if (array_key_exists('phone', $input)) {
            $phone = trim(Digits::toAscii((string) $input['phone']));
            $input['phone'] = $phone === '' ? null : $phone;
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', new PhoneNumber],
            'locale' => ['nullable', 'string', Rule::in(config('qistas.locales'))],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = $input['email'] !== $user->email;

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => array_key_exists('phone', $input) ? $input['phone'] : $user->phone,
            'locale' => $input['locale'] ?? $user->locale,
            // A new address has not been proven yet.
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ]);

        $changed = array_keys($user->getDirty());
        $user->save();

        $fields = array_values(array_intersect(['name', 'email', 'phone', 'locale'], $changed));
        if ($fields !== []) {
            Audit::record('profile.updated', $user, ['fields' => $fields], userId: $user->id);
        }

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }
    }
}
