<?php

namespace App\Domain\LateFees;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use App\Support\Money;

/**
 * How a contract charges for lateness (Win Plan K4, decision D2): an amount, or a percent of what is still owed on the
 * instalment when the fee is charged; once, daily, weekly or monthly from the day after the grace days; capped per
 * instalment and per contract; and optionally for charity, kept apart from the shop's income.
 *
 * The workspace sets it on the tools page; each contract keeps a copy of it as it stood when the contract was made
 * (`contracts.late_fee_policy`), so a later change never alters an old deal.
 */
final readonly class LateFeePolicy
{
    public const TYPES = ['fixed', 'percent'];

    public const FREQUENCIES = ['once', 'daily', 'weekly', 'monthly'];

    /** The most an amount setting takes, comfortably inside decimal(18,4). */
    private const MAX_AMOUNT = '1000000000';

    /**
     * @param  string  $amount  two decimals: money for a fixed fee, a percent (at most 100) otherwise
     * @param  ?string  $capPerInstallment  the most an instalment's fees add up to (waived ones count); null for no cap
     * @param  ?string  $capPerContract  the same for the whole contract
     */
    public function __construct(
        public string $type,
        public string $amount,
        public string $frequency,
        public ?string $capPerInstallment = null,
        public ?string $capPerContract = null,
        public bool $charity = false,
    ) {}

    /** Declares the policy's settings for the tools page, in the order they are shown. */
    public static function register(SettingsRegistry $registry): void
    {
        $max = 'max:'.self::MAX_AMOUNT;
        $registry->register(new SettingDefinition('late_fees.type', Feature::LateFees, 'select', 'fixed', [],
            fn () => __('Late fee'), fn () => __('An amount, or a percent of what is still owed on the instalment.'),
            ['fixed' => fn () => __('An amount'), 'percent' => fn () => __('A percent')]));
        $registry->register(new SettingDefinition('late_fees.amount', Feature::LateFees, 'amount', '0.00', [$max],
            fn () => __('Fee amount or percent'), fn () => __('Zero charges nothing. New contracts take the policy as it is when they are made.')));
        $registry->register(new SettingDefinition('late_fees.frequency', Feature::LateFees, 'select', 'once', [],
            fn () => __('How often it is charged'), fn () => __('From the day after the grace days, while the instalment is unpaid.'),
            ['once' => fn () => __('Once'), 'daily' => fn () => __('Every day'), 'weekly' => fn () => __('Every week'), 'monthly' => fn () => __('Every month')]));
        $registry->register(new SettingDefinition('late_fees.cap_installment', Feature::LateFees, 'amount', '0.00', [$max],
            fn () => __('Most fees per instalment'), fn () => __('Zero means no cap.')));
        $registry->register(new SettingDefinition('late_fees.cap_contract', Feature::LateFees, 'amount', '0.00', [$max],
            fn () => __('Most fees per contract'), fn () => __('Zero means no cap.')));
        $registry->register(new SettingDefinition('late_fees.charity', Feature::LateFees, 'switch', false, [],
            fn () => __('Fees go to charity'), fn () => __('Kept apart from the business’s income in every total.')));
    }

    /** The workspace's policy for a contract made now: null when the switch is off or the fee is zero. */
    public static function forNewContract(Tenant $tenant): ?self
    {
        if (! Entitlements::for($tenant)->check(Feature::LateFees)->enabled()) {
            return null;
        }

        $settings = TenantSettings::for($tenant);
        $type = (string) $settings->get('late_fees.type');
        $amount = (string) $settings->get('late_fees.amount');
        if (! Money::isPositive($amount)) {
            return null;
        }
        if ($type === 'percent' && Money::cmp($amount, '100') > 0) {
            $amount = '100.00';
        }

        return new self(
            type: $type,
            amount: $amount,
            frequency: (string) $settings->get('late_fees.frequency'),
            capPerInstallment: self::cap((string) $settings->get('late_fees.cap_installment')),
            capPerContract: self::cap((string) $settings->get('late_fees.cap_contract')),
            charity: (bool) $settings->get('late_fees.charity'),
        );
    }

    /**
     * A contract's stored copy; null when it has none.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ! in_array($stored['type'] ?? null, self::TYPES, true) || ! in_array($stored['frequency'] ?? null, self::FREQUENCIES, true)) {
            return null;
        }

        return new self(
            type: $stored['type'],
            amount: Money::add((string) $stored['amount'], '0', 2),
            frequency: $stored['frequency'],
            capPerInstallment: self::cap((string) ($stored['cap_installment'] ?? '0')),
            capPerContract: self::cap((string) ($stored['cap_contract'] ?? '0')),
            charity: (bool) ($stored['charity'] ?? false),
        );
    }

    /** @return array{type: string, amount: string, frequency: string, cap_installment: ?string, cap_contract: ?string, charity: bool} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'amount' => $this->amount,
            'frequency' => $this->frequency,
            'cap_installment' => $this->capPerInstallment,
            'cap_contract' => $this->capPerContract,
            'charity' => $this->charity,
        ];
    }

    private static function cap(string $value): ?string
    {
        return Money::isPositive($value) ? Money::add($value, '0', 2) : null;
    }
}
