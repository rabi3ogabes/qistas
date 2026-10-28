<?php

use App\Actions\VoidTransaction;
use App\Documents\BusinessProfile;
use App\Domain\DayClose\SummaryText;
use App\Entitlements\Feature;

/*
| Daily-habit wave A, H4: the one sentence a close is shared as, built in one place (SummaryText) for the API, the web
| page and, through the API, the app. It reads well whether the day balanced, was over or short, had one payment, many or
| none, and shows cents only when there are some to show.
|
| Al-Fares is in Riyadh (UTC+3); noon UTC on 21 October 2026 is 15:00 there. Amounts have a no-break space after the
| currency, which the tests turn into a plain one.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->shop->update(['name' => 'Al-Fares']);
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->contract = closeContract($this->shop, overrides: ['principal' => '20000.00']);
    $this->travelTo('2026-10-21 12:00:00');
});

/** The sentence for the close of [$date] after [$counted] was counted, in the language the application is in. */
function sentence(object $test, string $counted, string $date = '2026-10-21'): string
{
    $close = closeDay($test->shop, $date, $test->sara, $counted);

    return str_replace("\u{00A0}", ' ', SummaryText::for($close, $test->shop));
}

it('says what was collected, in cash and otherwise, how many payments, and that the day balanced', function () {
    closeTake($this->contract, '3100.00', by: $this->sara);
    foreach (array_fill(0, 10, '480.00') as $amount) {
        closeTake($this->contract, $amount, 'card', by: $this->sara);
    }
    closeTake($this->contract, '520.00', 'bank_transfer', by: $this->sara);
    closeTake($this->contract, '0.01', by: $this->sara);
    closeTake($this->contract, '0.01', by: $this->sara);

    // Two cents of cash more than the sentence in the brief: the day has cents, so they all show.
    expect(sentence($this, '3100.02'))->toBe('Al-Fares, 21 Oct: collected SAR 8,420.02 (cash 3,100.02, other 5,320.00), 14 payments, difference 0.');
});

it('writes whole amounts without cents', function () {
    closeTake($this->contract, '3100.00', by: $this->sara);
    closeTake($this->contract, '5320.00', 'card', by: $this->sara);

    expect(sentence($this, '3100.00'))->toBe('Al-Fares, 21 Oct: collected SAR 8,420 (cash 3,100, other 5,320), 2 payments, difference 0.');
});

it('leaves the split out when the money all came the one way', function () {
    closeTake($this->contract, '300.00', by: $this->sara);
    closeTake($this->contract, '200.00', by: $this->sara);
    closeTake($this->contract, '400.00', 'card', by: $this->sara, at: '2026-10-20 09:00:00');

    expect(sentence($this, '500.00'))->toBe('Al-Fares, 21 Oct: collected SAR 500, 2 payments, difference 0.')
        ->and(sentence($this, '0.00', '2026-10-20'))->toBe('Al-Fares, 20 Oct: collected SAR 400, 1 payment, difference 0.');
});

it('says short by the amount that is missing', function () {
    closeTake($this->contract, '100.00', by: $this->sara);

    expect(sentence($this, '87.50'))->toBe('Al-Fares, 21 Oct: collected SAR 100.00, 1 payment, short by SAR 12.50.');
});

it('says over by the amount that is more', function () {
    closeTake($this->contract, '100.00', by: $this->sara);

    expect(sentence($this, '112.50'))->toBe('Al-Fares, 21 Oct: collected SAR 100.00, 1 payment, over by SAR 12.50.');
});

it('says so when there were no payments', function () {
    expect(sentence($this, '0.00'))->toBe('Al-Fares, 21 Oct: no payments collected, difference 0.');
});

it('does not count a payment that was voided the same day', function () {
    $payment = closeTake($this->contract, '60.00', by: $this->sara);
    closeTake($this->contract, '40.00', by: $this->sara);
    app(VoidTransaction::class)->handle($payment, 'Taken twice', closeStaff($this->shop, 'Boss Owner', 'owner'));

    expect(sentence($this, '40.00'))->toBe('Al-Fares, 21 Oct: collected SAR 40, 1 payment, difference 0.');
});

it('puts the year on a day of an earlier year', function () {
    $this->travelTo('2027-01-03 12:00:00');

    expect(sentence($this, '0.00', '2026-12-30'))->toBe('Al-Fares, 30 Dec 2026: no payments collected, difference 0.')
        ->and(sentence($this, '0.00', '2027-01-02'))->toBe('Al-Fares, 2 Jan: no payments collected, difference 0.');
});

it('names the shop as its profile does in the language in use, and the workspace when it has no profile', function () {
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
    asTenant($this->shop, fn () => BusinessProfile::for($this->shop)->save(['name_ar' => 'الفارس', 'name_en' => 'Al-Fares Mobiles'], $this->sara));

    expect(SummaryText::for($close, $this->shop))->toStartWith('Al-Fares Mobiles, 21 Oct:');

    app()->setLocale('ar');
    expect(SummaryText::for($close, $this->shop))->toStartWith('الفارس، 21 أكتوبر:');
});

it('is built from the figures kept with the close, not from the day as it is now', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
    $before = SummaryText::for($close, $this->shop);

    closeTake($this->contract, '25.00', by: $this->sara);

    expect(SummaryText::for($close->fresh(), $this->shop))->toBe($before)
        ->and(str_replace("\u{00A0}", ' ', $before))->toBe('Al-Fares, 21 Oct: collected SAR 100, 1 payment, difference 0.');
});

it('writes every language with its own words, and a count that does not depend on the number', function (string $lang) {
    closeTake($this->contract, '100.00', by: $this->sara);
    closeTake($this->contract, '50.00', 'card', by: $this->sara);
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '90.00');
    app()->setLocale($lang);

    $text = SummaryText::for($close, $this->shop);

    // Every placeholder was filled in, and no English word is left in another language.
    expect($text)->not->toMatch('/:[a-z_]+/')->and($text)->not->toMatch('/\b(collected|payments?|short by|over by|difference|cash|other)\b/')
        ->and($text)->toContain('150')->toContain('100')->toContain('50')->toContain('10');
})->with(['ar', 'fr', 'es', 'ur']);

it('is only ever a string of the close\'s own figures', function () {
    // A close of a day with a customer who has an ID and a phone says neither.
    closeTake($this->contract, '100.00', by: $this->sara);
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00', 'A note that stays out of the sentence');

    expect(SummaryText::for($close, $this->shop))->not->toContain('Ahmad')->not->toContain('1098765432')->not->toContain('A note');
});
