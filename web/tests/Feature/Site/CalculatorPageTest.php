<?php

use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;

/*
| Win Plan PP15: the instalment calculator on the website, for people searching "حاسبة الأقساط". The same engine that
| writes real contracts makes the numbers (through the public preview); the page reads its inputs from the address, so a
| shared link opens with the same plan; and it carries questions and answers as FAQPage data for search, in the reader's
| language.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

/** @return array<string, mixed> the page's FAQPage data */
function faqData(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
    foreach ($blocks[1] as $block) {
        $data = json_decode($block, true);
        if (($data['@type'] ?? null) === 'FAQPage') {
            return $data;
        }
    }

    return [];
}

it('renders in each language, with its questions and answers as FAQ data', function (string $language, string $heading) {
    $response = $this->get('/installment-calculator?lang='.$language)->assertOk()->assertSee($heading);

    $faq = faqData($response->getContent());
    expect($faq['@context'] ?? null)->toBe('https://schema.org')
        ->and($faq['mainEntity'] ?? [])->not->toBeEmpty();
    foreach ($faq['mainEntity'] as $question) {
        expect($question['@type'])->toBe('Question')->and($question['name'])->not->toBe('')
            ->and($question['acceptedAnswer']['@type'])->toBe('Answer')->and($question['acceptedAnswer']['text'])->not->toBe('');
        // Every question shown in the data is also on the page for people to read.
        $response->assertSee($question['name']);
    }
})->with([
    ['en', 'Instalment calculator'],
    ['ar', 'حاسبة الأقساط'],
    ['fr', 'Calculateur de mensualités'],
    ['es', 'Calculadora de cuotas'],
    ['ur', 'قسطوں کا کیلکولیٹر'],
]);

it('opens a shared link with the plan in it, worked out by the contract engine', function () {
    $html = $this->get('/installment-calculator?price=5000&down=1000&markup=15&count=12&currency=SAR&lang=en')->assertOk()->getContent();

    $expected = app(ScheduleGenerator::class)->generate(ScheduleRequest::fromArray([
        'principal' => '5000', 'down_payment' => '1000', 'markup_type' => 'percent', 'markup_value' => '15',
        'count' => 12, 'frequency' => 'monthly', 'first_due_date' => '2026-11-11',
    ]))->toArray();

    // The same numbers the public preview answers with for the same plan.
    $preview = $this->postJson('/schedule-preview', [
        'principal' => '5000', 'down_payment' => '1000', 'markup_type' => 'percent', 'markup_value' => '15', 'count' => 12, 'frequency' => 'monthly',
    ])->assertOk()->json();

    expect($preview['installments'])->toEqual($expected['installments'])
        ->and($html)->toContain('383.33')->toContain('11 Oct 2027')
        ->and($html)->toContain('&quot;price&quot;:&quot;5000&quot;')->toContain('&quot;count&quot;:&quot;12&quot;');
});

it('falls back to the example plan for inputs it cannot use, instead of failing', function (string $query) {
    $this->get('/installment-calculator?'.$query)->assertOk()->assertSee('&quot;price&quot;:&quot;1200&quot;', false);
})->with([
    'nothing usable' => 'price=lots&down=-5&count=9999&markup=abc&currency=XXX',
    'too many instalments' => 'price=5000&count=9999',
    'no instalments' => 'price=5000&count=0',
    'a down payment as large as the price' => 'price=5000&down=5000',
    'a profit over 100%' => 'price=5000&markup=150',
]);

it('is listed for search engines and linked from the website', function () {
    $this->get('/sitemap.xml')->assertOk()->assertSee(url('/installment-calculator'));
    $this->get('/')->assertOk()->assertSee(route('calculator'), false);
});
