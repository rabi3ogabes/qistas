<?php

namespace App\Http\Controllers;

use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Entitlements\Feature;
use App\Site\PricingCatalog;
use App\Support\Format;
use App\Support\Locale;
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

    public function pricing(): View
    {
        return view('site.pricing', ['offers' => $this->catalog->offers(), 'features' => Feature::cases()]);
    }

    public function terms(): View
    {
        return $this->legal('terms', __('Terms of Service'), '/terms');
    }

    public function privacy(): View
    {
        return $this->legal('privacy', __('Privacy Policy'), '/privacy');
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
