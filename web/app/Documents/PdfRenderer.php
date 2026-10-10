<?php

namespace App\Documents;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use LogicException;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Turns a Blade view into PDF bytes: A4, the brand's fonts, a header and footer on every page, and the right
 * direction and font for the language (Arabic and Urdu run right to left, shaped with IBM Plex Sans Arabic).
 *
 * When [$quota] is given, one unit of that monthly allowance is used, but only once the document exists: a render that
 * fails costs the workspace nothing.
 *
 * The view extends `documents.layout`; it may use `$branding` (logo path and accent colour; Qistas by default).
 *
 * [$page] sets the paper (A4 by default, A5, or a till roll [width, height] in millimetres), the size of the body text
 * and a smaller header. A till roll is cut as long as what is printed on it.
 */
final class PdfRenderer
{
    /** What a document looks like when a workspace has no branding of its own: the brand's gold and navy, and no logo. */
    public const BRAND = ['accent' => '#C9A25B', 'ink' => '#0B1F44', 'logo' => null];

    /**
     * @param  array<string, mixed>  $data
     * @param  array{accent?: string, ink?: string, logo?: string|null, name?: string|null, footer?: string|null, qistas?: bool}  $branding
     * @param  array{format?: string|array{0: int, 1: int}, font_size?: float, compact?: bool, roll?: bool}  $page
     *
     * @throws InvalidArgumentException when the view does not exist
     * @throws LogicException when an allowance is asked for and no workspace is active
     */
    public function render(string $view, array $data, string $language, ?Feature $quota = null, array $branding = [], array $page = []): string
    {
        if (! View::exists($view)) {
            throw new InvalidArgumentException("There is no document view [{$view}].");
        }

        // An allowance belongs to a workspace: asking for one with none active is a mistake, not a free document.
        $tenant = $quota === null ? null : (app(CurrentTenant::class)->get() ?? throw new LogicException('A document that uses an allowance needs an active workspace.'));

        $rtl = in_array($language, (array) config('qistas.rtl_locales'), true);
        $previous = App::getLocale();
        App::setLocale($language);

        try {
            $html = view($view, $data + ['language' => $language, 'rtl' => $rtl, 'branding' => $branding + self::BRAND, 'fontSize' => $page['font_size'] ?? 10.5, 'compact' => $page['compact'] ?? false])->render();
        } finally {
            App::setLocale($previous);
        }

        // Number formatting in Arabic and Urdu adds invisible direction marks; the PDF engine orders the text itself, and
        // the brand's fonts have no shape for them (they would print as boxes).
        $html = (string) preg_replace('/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $html);

        $pdf = $this->engine($rtl, $page);
        $pdf->WriteHTML($html);

        // A till roll: written once on a very long page to see how far the text went, then again on a page that long.
        if (($page['roll'] ?? false) && is_array($page['format'] ?? null)) {
            $height = (int) ceil($pdf->y + 6);
            $pdf = $this->engine($rtl, ['format' => [$page['format'][0], max(60, $height)]] + $page);
            $pdf->WriteHTML($html);
        }

        $bytes = $pdf->Output('', Destination::STRING_RETURN);

        if ($quota !== null && $tenant !== null) {
            Entitlements::for($tenant)->consume($quota);
        }

        return $bytes;
    }

    /** @param  array{format?: string|array{0: int, 1: int}, font_size?: float, compact?: bool, roll?: bool}  $page */
    private function engine(bool $rtl, array $page = []): Mpdf
    {
        $roll = $page['roll'] ?? false;
        $compact = $page['compact'] ?? false;

        // Next to Laravel's other scratch folders; the system's temp folder on a host where storage is read-only.
        $temp = is_writable(storage_path('framework')) ? storage_path('framework/mpdf') : sys_get_temp_dir().'/qistas-mpdf';
        File::ensureDirectoryExists($temp);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $page['format'] ?? 'A4',
            'tempDir' => $temp,
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + [
                'geist' => ['R' => 'Geist-Regular.ttf', 'B' => 'Geist-Bold.ttf'],
                'plexarabic' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'default_font' => $rtl ? 'plexarabic' : 'geist',
            'default_font_size' => $page['font_size'] ?? 10.5,
            'margin_left' => $roll ? 4 : 16,
            'margin_right' => $roll ? 4 : 16,
            'margin_top' => $roll ? 4 : ($compact ? 22 : 30),
            'margin_bottom' => $roll ? 4 : 24,
            'margin_header' => $roll ? 0 : ($compact ? 7 : 10),
            'margin_footer' => $roll ? 0 : 10,
            // mPDF would otherwise swap in its own Arabic font (XBRiyaz) for Arabic text: the brand's fonts are the
            // only ones used, and a character one lacks (Arabic in an English document, Latin in an Arabic one) comes
            // from the other.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useSubstitutions' => true,
            'backupSubsFont' => $rtl ? ['plexarabic', 'geist'] : ['geist', 'plexarabic'],
        ]);

        $pdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $pdf->SetCreator('Qistas');

        return $pdf;
    }
}
