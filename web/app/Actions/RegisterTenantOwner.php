<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-service sign-up: one person, one new workspace, owner of it. The caller validates the input.
 * Everything happens in one transaction, so a failure leaves nothing half-created.
 */
final class RegisterTenantOwner
{
    /**
     * @param  array{name: string, email: string, password: string, business_name: string, country: string, locale?: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
            ]);

            $country = strtoupper($data['country']);

            $tenant = new Tenant([
                'name' => $data['business_name'],
                'slug' => $this->uniqueSlug($data['business_name']),
                'country' => $country,
                'currency' => config("qistas.countries.{$country}", config('qistas.currency_default')),
            ]);
            $tenant->owner_user_id = $user->id;
            $tenant->save();

            $tenant->users()->attach($user->id, ['role' => 'owner']);
            $tenant->subscribeTo(Plan::default());

            Audit::record('account.registered', $tenant, tenantId: $tenant->id, userId: $user->id);

            return $user;
        });
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
