<?php

namespace App\Domain\DayClose;

use App\Documents\BusinessProfile;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The one line a close is shared as (daily-habit wave A, H4), for the person to send on WhatsApp: the shop, the day, what
 * was collected and how, how many payments, and whether the cash balanced. The API sends it as `summary_text`, the web
 * page shows the same sentence, and the app shows what the API sent, so it is built here and nowhere else.
 *
 *     Al-Fares, 21 Oct: collected SAR 8,420 (cash 3,100, other 5,320), 14 payments, difference 0.
 *
 * It is read from the figures kept with the close, never from the day as it is now, so a close says the same thing
 * however much is recorded for its day afterwards. It is written in the language the application is in (the request's),
 * with the shop under the name it gives in that language, the day on that language's calendar, and the money the way
 * the language writes it. A count is phrased so that every language can use one form (English has the one and the many).
 *
 * What it leaves out, on purpose: who counted, the note, and every customer.
 */
final class SummaryText
{
    /**
     * @param  ?string  $shop  the shop's name in the language in use, when the caller already has it (a page of closes asks
     *                         once, not once for each); left out, it is read here
     */
    public static function for(DayClose $close, Tenant $tenant, ?string $shop = null): string
    {
        $language = app()->getLocale();
        $totals = $close->totals;

        /** @var array<string, string> $byMethod */
        $byMethod = $totals['by_method'] ?? [];
        $count = (int) ($totals['count'] ?? 0);

        $total = '0.00';
        foreach ($byMethod as $amount) {
            $total = Money::add($total, $amount, 2);
        }
        $cash = Money::add($byMethod['cash'] ?? '0', '0', 2);
        $other = Money::sub($total, $cash, 2);
        $difference = Money::add($close->difference, '0', 2);

        // Cents are written when any figure has them, in every figure, so nothing is rounded away and no row looks
        // different from the next; a day of whole amounts reads without them.
        $decimals = array_filter([$total, $cash, $other, $difference], fn (string $amount): bool => ! str_ends_with($amount, '.00')) === [] ? 0 : 2;
        $currency = (string) $tenant->currency;
        $money = fn (string $amount): string => Format::money($amount, $currency, $decimals);

        $day = CarbonImmutable::parse($close->day, 'UTC')->locale($language);
        $sameYear = $day->year === $tenant->localTime(CarbonImmutable::now())->year;

        $words = [
            'shop' => $shop ?? BusinessProfile::for($tenant)->name($language),
            'date' => $day->translatedFormat($sameYear ? 'j M' : 'j M Y'),
            'difference' => match (Money::cmp($difference, '0')) {
                0 => __('difference 0'),
                1 => __('over by :amount', ['amount' => $money($difference)]),
                default => __('short by :amount', ['amount' => $money(ltrim($difference, '-'))]),
            },
        ];

        // A day with nothing in it, not even a payment given back.
        if ($count === 0 && Money::isZero($total) && Money::isZero($cash)) {
            return __(':shop, :date: no payments collected, :difference.', $words);
        }

        $words += [
            'total' => $money($total),
            'payments' => $count === 1 ? __('1 payment') : __(':count payments', ['count' => $count]),
        ];

        // The split is told when there is one: money that all came the one way needs no brackets.
        if (Money::isZero($cash) || Money::isZero($other)) {
            return __(':shop, :date: collected :total, :payments, :difference.', $words);
        }

        return __(':shop, :date: collected :total (cash :cash, other :other), :payments, :difference.', $words + [
            'cash' => Format::amount($cash, $decimals),
            'other' => Format::amount($other, $decimals),
        ]);
    }
}
