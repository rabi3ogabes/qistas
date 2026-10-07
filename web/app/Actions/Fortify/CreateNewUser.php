<?php

namespace App\Actions\Fortify;

use App\Actions\RegisterTenantOwner;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/** Validates the sign-up form, then opens the account: user, workspace and owner membership. */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'business_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'locale' => ['nullable', 'string', Rule::in(config('qistas.locales'))],
            'terms' => ['accepted'],
        ])->validate();

        return app(RegisterTenantOwner::class)->handle([
            ...Arr::only($input, ['name', 'email', 'password', 'business_name', 'country']),
            'locale' => $input['locale'] ?? app()->getLocale(),
        ]);
    }
}
