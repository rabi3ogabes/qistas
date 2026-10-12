<?php

use App\Support\AmountInWords;

/*
| An amount in words for a promissory note (Win Plan K5). English, French and Spanish come from ICU. Arabic is written
| by our own rules, because ICU writes hundreds as two words ("ثمانية مائة") where a note must say "ثمانمائة".
*/

it('writes Arabic amounts the way a cheque or a note writes them', function (string $amount, string $words) {
    expect(AmountInWords::say($amount, 'SAR', 'ar'))->toBe($words.' ريال سعودي');
})->with([
    'one' => ['1.00', 'واحد'],
    'twenty-one' => ['21', 'واحد وعشرون'],
    'eleven and twelve' => ['12', 'اثنا عشر'],
    'hundreds in one word' => ['800', 'ثمانمائة'],
    'two hundred' => ['200', 'مائتان'],
    'a thousand and a hundred' => ['1100', 'ألف ومائة'],
    'two thousand' => ['2000', 'ألفان'],
    'thousands three to ten' => ['10800', 'عشرة آلاف وثمانمائة'],
    'thousands from eleven' => ['15000', 'خمسة عشر ألف'],
    'hundreds of thousands' => ['250375', 'مائتان وخمسون ألف وثلاثمائة وخمسة وسبعون'],
    'a million and more' => ['3001001', 'ثلاثة ملايين وألف وواحد'],
]);

it('adds the hundredths as a fraction, in every language', function () {
    expect(AmountInWords::say('1250.50', 'SAR', 'ar'))->toBe('ألف ومائتان وخمسون ريال سعودي و50/100')
        ->and(AmountInWords::say('1250.50', 'SAR', 'en'))->toBe('one thousand two hundred fifty Saudi Riyal and 50/100');
});

it('takes English, French and Spanish from ICU, with the currency’s own name', function () {
    expect(AmountInWords::say('10800', 'SAR', 'en'))->toBe('ten thousand eight hundred Saudi Riyal')
        ->and(AmountInWords::say('10800', 'SAR', 'fr'))->toBe('dix mille huit cents riyal saoudien')
        ->and(AmountInWords::say('1100', 'KWD', 'en'))->toBe('one thousand one hundred Kuwaiti Dinar');
});
