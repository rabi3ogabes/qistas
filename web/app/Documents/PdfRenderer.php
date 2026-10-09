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
 */
final class PdfRenderer
{
    /** What a document looks like when a workspace has no branding of its own: the brand's gold and navy, and no logo. */
    public const BRAND = ['accent' => '#C9A25B', 'ink' => '#0B1F44', 'logo' => null];

    /**
     * @param  array<string, mixed>  $data
     * @param  array{accent?: string, ink?: string, logo?: string|null}  $branding
     *
     * @throws InvalidArgumentException when the view does not exist
     * @throws LogicException when an allowance is asked for and no workspace is active
     */
    public function render(string $view, array $data, string $language, ?Feature $quota = null, array $branding = []): string
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
            $html = view($view, $data + ['language' => $language, 'rtl' => $rtl, 'branding' => $branding + self::BRAND])->render();
        } finally {
            App::setLocale($previous);
        }

        $pdf = $this->engine($rtl);
        $pdf->WriteHTML($html);
        $bytes = $pdf->Output('', Destination::STRING_RETURN);

        if ($quota !== null && $tenant !== null) {
            Entitlements::for($tenant)->consume($quota);
        }

        return $bytes;
    }

    private function engine(bool $rtl): Mpdf
    {
        // Next to Laravel's other scratch folders; the system's temp folder on a host where storage is read-only.
        $temp = is_writable(storage_path('framework')) ? storage_path('framework/mpdf') : sys_get_temp_dir().'/qistas-mpdf';
        File::ensureDirectoryExists($temp);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $temp,
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + [
                'geist' => ['R' => 'Geist-Regular.ttf', 'B' => 'Geist-Bold.ttf'],
                'plexarabic' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'default_font' => $rtl ? 'plexarabic' : 'geist',
            'default_font_size' => 10.5,
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 30,
            'margin_bottom' => 24,
            'margin_header' => 10,
            'margin_footer' => 10,
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
