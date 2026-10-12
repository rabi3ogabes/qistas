<?php

namespace App\Http\Controllers;

use App\Actions\Account\RequestAccountDeletion;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Entitlements\Feature;
use App\Site\PricingCatalog;
use App\Site\SwitchGuide;
use App\Support\Format;
use App\Support\Locale;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public website. Everything that depends on plans is read live from the plans the admin edits. */
final class SiteController
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly ScheduleGenerator $generator,
    ) {}

    public function home(Request $request): View
    {
        $currency = Locale::currencyFor($request);

        // A starting example for the calculator, rendered with the page so it never opens empty.
        $initial = $this->generator->generate(ScheduleRequest::fromArray([
            'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
            'count' => 6, 'frequency' => 'monthly', 'first_due_date' => now()->addMonthNoOverflow()->format('Y-m-d'),
        ]));

        return view('site.home', [
            'offers' => $this->catalog->offers(),
            'freeCustomers' => $this->catalog->freeAllowance(Feature::Customers),
            'currency' => $currency,
            'currencies' => array_values(array_unique([$currency, ...Locale::currencies()])),
            'initialSchedule' => $initial->toArray(),
            'initialEach' => Format::money($initial->installments[0]['amount'], $currency),
        ]);
    }

    /**
     * The instalment calculator for search (Win Plan PP15). The plan comes from the address, so a shared link opens with
     * the same numbers; anything unusable falls back to the example. The numbers are the contract engine's.
     */
    public function calculator(Request $request): View
    {
        $plan = self::calculatorInputs($request);
        $currency = in_array($request->query('currency'), Locale::currencies(), true) ? (string) $request->query('currency') : Locale::currencyFor($request);

        $schedule = $this->generator->generate(ScheduleRequest::fromArray([
            'principal' => $plan['price'], 'down_payment' => $plan['down'],
            'markup_type' => Money::isPositive($plan['markup']) ? 'percent' : 'none', 'markup_value' => $plan['markup'],
            'count' => (int) $plan['count'], 'frequency' => 'monthly', 'first_due_date' => now()->addMonthNoOverflow()->format('Y-m-d'),
        ]));
        $faq = self::calculatorQuestions();

        return view('site.calculator', [
            'plan' => $plan,
            'currency' => $currency,
            'currencies' => array_values(array_unique([$currency, ...Locale::currencies()])),
            'initialSchedule' => $schedule->toArray(),
            'initialEach' => Format::money($schedule->installments[0]['amount'], $currency),
            'faq' => $faq,
            'faqData' => [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $qa) => [
                    '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
                ], $faq),
            ],
        ]);
    }

    /** @return array{price: string, down: string, markup: string, count: string} */
    private static function calculatorInputs(Request $request): array
    {
        $example = ['price' => '1200', 'down' => '200', 'markup' => '10', 'count' => '6'];
        $number = fn (string $key): ?string => is_string($value = $request->query($key)) && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) ? $value : null;

        $price = $number('price');
        $down = $number('down');
        $markup = $number('markup');
        $count = is_string($value = $request->query('count')) && ctype_digit($value) ? (int) $value : null;
        if ($price === null || ! Money::isPositive($price) || ($down !== null && Money::cmp($down, $price) >= 0)
            || ($markup !== null && Money::cmp($markup, '100') > 0) || ($count !== null && ($count < 1 || $count > ScheduleGenerator::BASIC_MAX_COUNT))) {
            return $example;
        }

        return ['price' => $price, 'down' => $down ?? '0', 'markup' => $markup ?? '0', 'count' => (string) ($count ?? 6)];
    }

    /** @return list<array{0: string, 1: string}> the calculator page's questions and answers, in the reader's language */
    private static function calculatorQuestions(): array
    {
        return [
            [__('How is each instalment worked out?'), __('The down payment comes off the price, the profit is added to what is left, and the total is split into equal instalments. The last one takes the few cents left over, so the instalments add up to the total exactly.')],
            [__('Is the profit worked out on the price or on what is financed?'), __('On what is financed: the price less the down payment, as most shops do. A 10% profit on 1,000 financed is 100.')],
            [__('When is the last instalment due?'), __('The first instalment falls a month from today and each one after it a month later. The calculator shows the date of the last one.')],
            [__('Can I send a plan to a customer?'), __('Yes. The page’s address holds the numbers: copy the link and send it, and it opens with the same plan.')],
            [__('Is it the same as in the Qistas app?'), __('Yes. The calculator uses the same engine that writes contracts in Qistas, so a contract made from these numbers has exactly these instalments.')],
            [__('Is the calculator free?'), __('Yes, for anyone, with no sign-up.')],
        ];
    }

    public function pricing(): View
    {
        return view('site.pricing', ['offers' => $this->catalog->offers(), 'features' => Feature::planFeatures()]);
    }

    public function terms(): View
    {
        return $this->legal('terms', __('Terms of Service'), '/terms');
    }

    public function privacy(): View
    {
        return $this->legal('privacy', __('Privacy Policy'), '/privacy');
    }

    /** For a shop moving to Qistas from a paper notebook (Sprint 2, D10). */
    public function fromNotebook(): View
    {
        return $this->switching('notebook');
    }

    /** For a shop moving to Qistas from a spreadsheet. */
    public function fromExcel(): View
    {
        return $this->switching('excel');
    }

    /** For a shop moving to Qistas from another instalment app. */
    public function fromAnotherApp(): View
    {
        return $this->switching('app');
    }

    private function switching(string $from): View
    {
        return view('site.switch', ['from' => $from, 'guide' => SwitchGuide::for($from)]);
    }

    /** How to delete a Qistas account, and what happens to the data: for anyone, signed in or not (store requirement). */
    public function accountDeletion(): View
    {
        return view('site.account-delete', ['days' => RequestAccountDeletion::DAYS_TO_RESTORE]);
    }

    /** A legal document in the reader's language, or in English (marked as such) when it is not translated yet. */
    private function legal(string $document, string $title, string $path): View
    {
        $localised = "site.legal.{$document}-".app()->getLocale();
        $available = view()->exists($localised);

        return view('site.legal.show', [
            'title' => $title,
            'path' => $path,
            'document' => $available ? $localised : "site.legal.{$document}-en",
            'fallback' => ! $available,
        ]);
    }
}
