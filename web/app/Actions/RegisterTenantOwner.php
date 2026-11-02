<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\SignupPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-service sign-up: one person, one new workspace, owner of it. The caller validates the input.
 * Everything happens in one transaction, so a failure leaves nothing half-created.
 *
 * A sign-up needs only the person. Given a business name the workspace is named from it and counts as confirmed (an older
 * client, the developer seeder, a staff test workspace); without one it is named after the person, in their language,
 * and stays unconfirmed until the owner confirms the name and place on the first-run card.
 */
final class RegisterTenantOwner
{
    /** The longest part of the person's name used in the placeholder, so that a long name cannot overflow the column. */
    private const FIRST_NAME_LIMIT = 40;

    /**
     * @param  array{name: string, email: string, password: string, locale?: string|null, business_name?: string|null, country?: string|null, country_source?: string|null, timezone?: string|null}  $data  country_source is header, language, default or chosen; given a country without one, the country was chosen
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $locale = $data['locale'] ?? 'en';

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $locale,
            ]);

            $businessName = trim((string) ($data['business_name'] ?? ''));
            $name = $businessName !== '' ? $businessName : $this->placeholderName($data['name'], $locale);

            $given = trim((string) ($data['country'] ?? ''));
            $country = $given !== '' ? strtoupper($given) : SignupPlace::defaultCountry();

            $tenant = new Tenant([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'country' => $country,
                'country_source' => $given !== '' ? ($data['country_source'] ?? 'chosen') : 'default',
                'currency' => config("qistas.countries.{$country}", config('qistas.currency_default')),
                'timezone' => SignupPlace::timezone($country, $data['timezone'] ?? null, null),
            ]);
            $tenant->owner_user_id = $user->id;

            if ($businessName !== '') {
                $tenant->setup_confirmed_at = now();
            }

            $tenant->save();

            $tenant->users()->attach($user->id, ['role' => 'owner']);
            $tenant->subscribeTo(Plan::default());

            Audit::record('account.registered', $tenant, tenantId: $tenant->id, userId: $user->id);

            return $user;
        });
    }

    /** "Business of Layla", in the person's own language (not the request's): the first word of their name, kept short. */
    private function placeholderName(string $personName, string $locale): string
    {
        $words = preg_split('/\s+/u', trim($personName), -1, PREG_SPLIT_NO_EMPTY);
        $first = mb_substr($words[0] ?? '', 0, self::FIRST_NAME_LIMIT);

        return __('Business of :name', ['name' => $first], $locale);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
