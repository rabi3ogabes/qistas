<?php

namespace App\Sandbox;

use App\Actions\CreateContract;
use App\Actions\CreateCustomer;
use App\Actions\RecordPayment;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A believable small business to look around in: a few customers, running contracts, one with an instalment
 * overdue and one due today, and some payments. It goes through the very same actions the app uses, so every
 * ledger rule holds exactly as for real data. Dates are relative to today, so "overdue" and "due today" stay true.
 */
final class SampleBusiness
{
    public function __construct(
        private readonly CreateCustomer $customers,
        private readonly CreateContract $contracts,
        private readonly RecordPayment $payments,
    ) {}

    public function populate(Tenant $tenant, User $by): void
    {
        $customer = fn (string $name, string $phone) => $this->customers->handle($tenant, ['name' => $name, 'phone' => $phone], $by);
        $open = fn ($customer, array $terms) => $this->contracts->handle($tenant, ['customer_id' => $customer->id, ...$terms], $by);
        $pay = fn (Contract $contract, string $amount, string $when) => $this->payments->handle(
            $contract, $amount, 'cash', by: $by, paidAt: Carbon::parse($when),
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
