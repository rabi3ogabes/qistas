<?php

namespace Database\Seeders;

use App\Actions\RegisterTenantOwner;
use App\Models\User;
use App\Sandbox\SampleBusiness;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A believable demo business for local development and screenshots: one workspace on the Free plan with a few
 * customers, contracts, payments, something overdue and something due today (see App\Sandbox\SampleBusiness).
 * Never runs in production.
 *
 *     php artisan db:seed --class=DemoSeeder      # then sign in as demo@qistas.test
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@qistas.test';

    /** A local-only password for the demo account. */
    public const PASSWORD = 'Demo!Passw0rd2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo data is for local development only.');
        }
        if (User::where('email', self::EMAIL)->exists()) {
            $this->command?->info('Demo workspace already exists; leaving it alone.');

            return;
        }

        $owner = app(RegisterTenantOwner::class)->handle([
            'name' => 'Layla Haddad', 'email' => self::EMAIL, 'password' => self::PASSWORD,
            'business_name' => 'Al-Fares Electronics', 'country' => 'SA', 'locale' => 'en',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        app(SampleBusiness::class)->populate($owner->tenants()->sole(), $owner);
    }
}
