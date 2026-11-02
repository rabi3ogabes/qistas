<?php

namespace App\Actions\Fortify;

use App\Actions\RegisterTenantOwner;
use App\Models\User;
use App\Support\SignupPlace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Validates the sign-up form, then opens the account: user, workspace and owner membership. Used by the web form and the
 * phone app alike, so both have the same rules. A person gives a name, an e-mail, a password and the terms; where they
 * are is found from the request (SignupPlace). A client that still sends a business name or a country is believed.
 */
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
            'business_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            // A browser reports its IANA zone, the phone app its offset from UTC in minutes (SignupPlace::timezone).
            'timezone' => ['nullable', 'string', 'max:64'],
            'utc_offset' => ['nullable', 'integer', 'between:-840,840'],
            'locale' => ['nullable', 'string', Rule::in(config('qistas.locales'))],
            'terms' => ['accepted'],
        ])->validate();

        $detected = SignupPlace::detect(request());
        $chosen = filled($input['country'] ?? null);
        $country = $chosen ? strtoupper((string) $input['country']) : $detected['country'];
        $offset = filled($input['utc_offset'] ?? null) ? (int) $input['utc_offset'] : null;

        return app(RegisterTenantOwner::class)->handle([
            ...Arr::only($input, ['name', 'email', 'password', 'business_name']),
            'locale' => $input['locale'] ?? app()->getLocale(),
            'country' => $country,
            'country_source' => $chosen ? 'chosen' : $detected['source'],
            'timezone' => SignupPlace::timezone($country, is_string($input['timezone'] ?? null) ? $input['timezone'] : null, $offset),
        ]);
    }
}
