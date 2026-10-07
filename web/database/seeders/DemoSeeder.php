<?php

namespace Database\Seeders;

use App\Actions\CreateContract;
use App\Actions\CreateCustomer;
use App\Actions\RecordPayment;
use App\Actions\RegisterTenantOwner;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A believable demo business for local development and screenshots: one workspace on the Free plan with a few
 * customers, contracts, payments, something overdue and something due today. Built through the same actions the
 * app uses, so every ledger invariant holds. Never runs in production.
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
        $tenant = $owner->tenants()->sole();

        $customer = fn (string $name, string $phone) => app(CreateCustomer::class)->handle($tenant, ['name' => $name, 'phone' => $phone], $owner);
        $open = fn ($customer, array $terms) => app(CreateContract::class)->handle($tenant, ['customer_id' => $customer->id, ...$terms], $owner);
        $pay = fn (Contract $contract, string $amount, string $when) => app(RecordPayment::class)->handle(
            $contract, $amount, 'cash', by: $owner, paidAt: Carbon::parse($when),
        );

        $omar = $customer('Omar Khalil', '+966 55 123 4567');
        $noura = $customer('نورة السبيعي', '+966 50 987 6543');
        $youssef = $customer('Youssef Mansour', '+971 55 222 3344');
        $customer('Sara Al-Qahtani', '+966 54 111 0099');

        // Omar: a washing machine over 6 months, first instalment 70 days ago, paid up to date except the latest.
        $washer = $open($omar, [
            'type' => 'scheduled', 'principal' => '2400.00', 'down_payment' => '400.00', 'markup_type' => 'percent', 'markup_value' => '10',
            'installment_count' => 6, 'frequency' => 'monthly', 'start_date' => now()->subDays(75)->format('Y-m-d'),
            'first_due_date' => now()->subDays(70)->format('Y-m-d'),
        ]);
        $pay($washer, '366.67', now()->subDays(68)->format('Y-m-d H:i:s'));
        $pay($washer, '366.67', now()->subDays(38)->format('Y-m-d H:i:s'));

        // Noura: a laptop with an instalment due today.
        $laptop = $open($noura, [
            'type' => 'scheduled', 'principal' => '3000.00', 'down_payment' => '600.00', 'markup_type' => 'none', 'markup_value' => '0',
            'installment_count' => 4, 'frequency' => 'monthly', 'start_date' => now()->subDays(55)->format('Y-m-d'),
            'first_due_date' => now()->subMonth()->format('Y-m-d'),
        ]);
        $pay($laptop, '600.00', now()->subMonth()->format('Y-m-d H:i:s'));

        // Youssef: a phone on weekly instalments, partly paid.
        $phone = $open($youssef, [
            'type' => 'scheduled', 'principal' => '1800.00', 'down_payment' => '0', 'markup_type' => 'fixed', 'markup_value' => '90.00',
            'installment_count' => 9, 'frequency' => 'weekly', 'start_date' => now()->subDays(20)->format('Y-m-d'),
            'first_due_date' => now()->subDays(14)->format('Y-m-d'),
        ]);
        $pay($phone, '300.00', now()->subDays(13)->format('Y-m-d H:i:s'));
        $pay($phone, '150.00', now()->subDays(4)->format('Y-m-d H:i:s'));
    }
}
