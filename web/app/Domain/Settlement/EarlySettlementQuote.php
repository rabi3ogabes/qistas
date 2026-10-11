<?php

namespace App\Domain\Settlement;

use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What it costs to settle a contract today (Win Plan PP6, خصم سداد مبكر). Profit counts as money comes in, as the
 * investor ledger counts it: earned = round(paid on instalments x markup / total). The rebate is a percent of the profit
 * not yet earned, or an amount, never more than that profit, rounded half up to the cent; so the payoff always brings
 * back every cent of what was financed. Pure arithmetic: nothing is written.
 */
final readonly class EarlySettlementQuote
{
    public const REBATE_TYPES = ['none', 'percent', 'fixed'];

    private function __construct(
        public string $remaining,
        public string $paid,
        public string $earnedProfit,
        public string $unearnedProfit,
        public string $rebate,
        public string $payoff,
        public bool $capped,
        public string $rebateType,
        public string $rebateValue,
    ) {}

    /**
     * @param  Collection<int, Installment>  $installments  every instalment of the contract
     *
     * @throws InvalidArgumentException when the rebate cannot be read
     */
    public static function for(Contract $contract, Collection $installments, string $rebateType = 'none', string $rebateValue = '0'): self
    {
        if (! in_array($rebateType, self::REBATE_TYPES, true)) {
            throw new InvalidArgumentException('The rebate must be none, a percent or a fixed amount.');
        }
        $value = $rebateType === 'none' ? '0' : Money::parse($rebateValue);
        if (Money::isNegative($value) || ($rebateType === 'percent' && Money::cmp($value, '100') > 0)) {
            throw new InvalidArgumentException('A rebate is between 0 and 100 percent, or an amount of zero or more.');
        }

        $paid = $installments->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->paid_amount), '0');
        $remaining = $installments->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->remaining()), '0');
        $total = Money::add($contract->total, '0', 2);
        $markup = Money::add($contract->markup_amount, '0', 2);

        $earned = Money::isPositive($total) ? Money::round(Money::div(Money::mul($paid, $markup, 6), $total, 6), 2) : '0.00';
        if (Money::cmp($earned, $markup) > 0) {
            $earned = $markup;
        }
        $unearned = Money::sub($markup, $earned, 2);
        // Never more than is left to pay either (a contract paid ahead of its profit has nothing to give back).
        $ceiling = Money::cmp($unearned, $remaining) > 0 ? Money::add($remaining, '0', 2) : $unearned;

        $asked = match ($rebateType) {
            'percent' => Money::round(Money::div(Money::mul($unearned, $value, 8), '100', 8), 2),
            'fixed' => Money::round($value, 2),
            default => '0.00',
        };
        $capped = Money::cmp($asked, $ceiling) > 0;
        $rebate = $capped ? $ceiling : $asked;

        return new self(
            remaining: Money::add($remaining, '0', 2),
            paid: Money::add($paid, '0', 2),
            earnedProfit: $earned,
            unearnedProfit: $unearned,
            rebate: Money::add($rebate, '0', 2),
            payoff: Money::sub($remaining, $rebate, 2),
            capped: $capped,
            rebateType: $rebateType,
            rebateValue: Money::add($value, '0', $rebateType === 'percent' ? 4 : 2),
        );
    }

    /** @return array{remaining: string, earned_profit: string, unearned_profit: string, rebate: string, payoff: string, capped: bool, rebate_type: string, rebate_value: string} */
    public function toArray(): array
    {
        return [
            'remaining' => $this->remaining,
            'earned_profit' => $this->earnedProfit,
            'unearned_profit' => $this->unearnedProfit,
            'rebate' => $this->rebate,
            'payoff' => $this->payoff,
            'capped' => $this->capped,
            'rebate_type' => $this->rebateType,
            'rebate_value' => $this->rebateValue,
        ];
    }
}
