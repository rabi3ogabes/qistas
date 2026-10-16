<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // Checked against this person's own password, so it works the same from the website and from the app.
        Validator::make($input, [
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || ! Hash::check($value, $user->password)) {
                    $fail(__('The provided password does not match your current password.'));
                }
            }],
            'password' => $this->passwordRules(),
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save();
    }
}
