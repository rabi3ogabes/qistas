<?php

namespace App\Site;

use App\Entitlements\Feature;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Import\Ai\AiSettings;

/**
 * What the pages for a shop moving to Qistas say (Sprint 2, decision D10): how the move goes from a notebook, from a
 * spreadsheet or from another app. Each step promises only what is switched on for everyone right now: bringing a file
 * in once importing is on, and photos of the notebook and PDF reports once they are read too.
 */
final class SwitchGuide
{
    public const SOURCES = ['notebook', 'excel', 'app'];

    /** @return array{title: string, lead: string, steps: list<array{0: string, 1: string}>} */
    public static function for(string $from): array
    {
        $import = PlatformFeatures::state(Feature::Import) === PlatformState::On;
        $photos = $import && PlatformFeatures::state(Feature::AiReading) === PlatformState::On && app(AiSettings::class)->configured();

        $open = [__('Open your free workspace'), __('It takes a minute, with no card. Your notebook, sheet or old app stays as it is.')];
        $manual = [__('Add your customers'), __('A name, a phone and what they owe: each takes under a minute, and the instalments are worked out for you.')];
        $check = [__('Check the trial run, then confirm'), __('Every row and every problem is shown before anything is saved. Old balances become contracts, with what was already paid as one payment.')];
        $record = [__('Record payments as they come'), __('Each payment lands on the oldest instalment first, so every balance stays right.')];

        [$title, $lead, $middle] = match ($from) {
            'notebook' => [
                __('Move your notebook to Qistas'),
                __('Your notebook holds every customer, what they owe and when it is due. Qistas keeps the same list, works out each instalment to the cent and shows you who is due today.'),
                match (true) {
                    $photos => [[__('Photograph a page'), __('Take a photo of a notebook page. Each customer on it is read into a row, ready for you to check.')], $check],
                    $import => [[__('Copy the list into the template'), __('Fill the ready Excel template with your customers and what they owe, then bring it in at once.')], $check],
                    default => [$manual],
                },
            ],
            'excel' => [
                __('From your Excel sheet to Qistas'),
                __('Start from the sheet you know. Qistas takes over the sums, the due dates and the receipts, so nothing depends on a formula someone may break.'),
                $import
                    ? [[__('Bring your sheet in'), __('Choose your Excel or CSV file as it is. Columns are recognised in Arabic, English, French, Spanish and Urdu, and you can choose any column yourself.')], $check]
                    : [$manual],
            ],
            default => [
                __('Bring your books from another app'),
                __('Moving should not mean typing everything again. Bring your customers and what they still owe, and carry on from where you are.'),
                $import
                    ? [[__('Export from your old app'), $photos
                        ? __('Most apps save their customers as an Excel or CSV file: bring it in as it is. No export? The PDF reports your old app prints are read into the same rows.')
                        : __('Most apps save their customers as an Excel or CSV file: bring it in as it is, with its columns named in your language.')], $check]
                    : [$manual],
            ],
        };

        return ['title' => $title, 'lead' => $lead, 'steps' => [$open, ...$middle, $record]];
    }
}
