<?php

namespace App\Console\Commands;

use App\Documents\PdfRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Draws the sample document in one language so that the layout, the fonts and the Arabic can be checked by eye. */
final class PdfPreviewCommand extends Command
{
    protected $signature = 'qistas:pdf-preview {language=en : en, ar, fr, es or ur}';

    protected $description = 'Write a sample PDF (and a picture of it when pdftoppm is installed) to storage/app/previews';

    /** @var array<string, array{title: string, customer: string, lines: list<array{string, string}>}> */
    private const SAMPLES = [
        'en' => ['title' => 'Statement of account', 'customer' => 'Ahmad Salem', 'lines' => [['Instalment 1, due 1 October', '125.00'], ['Instalment 2, due 1 November', '125.00'], ['Instalment 3, due 1 December', '125.00']]],
        'ar' => ['title' => 'كشف حساب', 'customer' => 'أحمد سالم', 'lines' => [['القسط الأول، يستحق في 1 أكتوبر', '125.00'], ['القسط الثاني، يستحق في 1 نوفمبر', '125.00'], ['القسط الثالث، يستحق في 1 ديسمبر', '125.00']]],
        'fr' => ['title' => 'Relevé de compte', 'customer' => 'Ahmad Salem', 'lines' => [['Échéance 1, au 1er octobre', '125.00'], ['Échéance 2, au 1er novembre', '125.00'], ['Échéance 3, au 1er décembre', '125.00']]],
        'es' => ['title' => 'Estado de cuenta', 'customer' => 'Ahmad Salem', 'lines' => [['Cuota 1, vence el 1 de octubre', '125.00'], ['Cuota 2, vence el 1 de noviembre', '125.00'], ['Cuota 3, vence el 1 de diciembre', '125.00']]],
        'ur' => ['title' => 'اکاؤنٹ اسٹیٹمنٹ', 'customer' => 'احمد سالم', 'lines' => [['پہلی قسط، یکم اکتوبر', '125.00'], ['دوسری قسط، یکم نومبر', '125.00'], ['تیسری قسط، یکم دسمبر', '125.00']]],
    ];

    public function handle(PdfRenderer $renderer): int
    {
        $language = (string) $this->argument('language');

        if (! array_key_exists($language, self::SAMPLES)) {
            $this->components->error('Choose one of: '.implode(', ', array_keys(self::SAMPLES)).'.');

            return self::FAILURE;
        }

        $folder = storage_path('app/previews');
        File::ensureDirectoryExists($folder);
        $pdf = $folder.'/sample-'.$language.'.pdf';

        File::put($pdf, $renderer->render('documents.sample', self::SAMPLES[$language] + ['reference' => 'QST-0001', 'verifyUrl' => 'https://qistas.example/verify/QST-0001'], $language));
        $this->components->info("Wrote {$pdf}");

        try {
            $picture = Process::run(['pdftoppm', '-png', '-r', '80', '-singlefile', $pdf, $folder.'/sample-'.$language]);
            $picture->successful() && $this->components->info("Wrote {$folder}/sample-{$language}.png");
        } catch (Throwable) {
            $this->components->twoColumnDetail('Picture', 'skipped (pdftoppm is not installed)');
        }

        return self::SUCCESS;
    }
}
