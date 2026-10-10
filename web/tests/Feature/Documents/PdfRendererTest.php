<?php

use App\Documents\PdfRenderer;
use App\Documents\QrCode;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\LimitReached;
use Illuminate\View\ViewException;
use Smalot\PdfParser\Parser;

/*
| The PDF renderer is what statements, contracts and receipts will be made with. These tests read a rendered document
| back with a PDF parser, which is how a person's copy-and-paste or a search engine would see it, so "the Arabic is
| there and in the right order" is checked, not assumed.
*/

/** The text of a PDF, as a parser extracts it. */
function pdfText(string $pdf): string
{
    return (new Parser)->parseContent($pdf)->getText();
}

function pdfPages(string $pdf): int
{
    return count((new Parser)->parseContent($pdf)->getPages());
}

/** Shaped Arabic comes back from a parser as presentation forms, and sometimes visually reversed: compare in a neutral form. */
function normalised(string $text): string
{
    return preg_replace('/\s+/u', '', Normalizer::normalize($text, Normalizer::FORM_KC)) ?? '';
}

function reversedByCharacter(string $text): string
{
    return implode('', array_reverse(mb_str_split($text)));
}

function containsArabic(string $text, string $expected): bool
{
    $haystack = normalised($text);
    $needle = normalised($expected);

    return str_contains($haystack, $needle) || str_contains(reversedByCharacter($haystack), $needle) || str_contains($haystack, reversedByCharacter($needle));
}

beforeEach(function () {
    $this->tenant = workspaceOn('free');
    $this->renderer = app(PdfRenderer::class);
    $this->data = ['title' => 'Statement of account', 'customer' => 'Ahmad Salem', 'lines' => [['Instalment 1', '100.00'], ['Instalment 2', '100.00']], 'verifyUrl' => 'https://qistas.test/v/abc123'];
});

describe('a document', function () {
    it('is a real PDF that reads back as the English it was made from', function () {
        $pdf = $this->renderer->render('documents.sample', $this->data, 'en');

        expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(pdfPages($pdf))->toBe(1)
            ->and(pdfText($pdf))->toContain('Statement of account')->toContain('Ahmad Salem')->toContain('Instalment 2')->toContain('100.00');
    });

    it('reads back as the Arabic it was made from, whichever way the parser orders it', function (string $language) {
        $data = ['title' => 'كشف الحساب', 'customer' => 'أحمد سالم', 'lines' => [['القسط الأول', '100.00']]] + $this->data;
        $pdf = $this->renderer->render('documents.sample', $data, $language);
        $text = pdfText($pdf);

        expect(containsArabic($text, 'كشف الحساب'))->toBeTrue()
            ->and(containsArabic($text, 'أحمد سالم'))->toBeTrue()
            ->and($text)->toContain('100.00');
    })->with(['ar', 'ur']);

    it('writes its own labels in the document’s language, whatever language the person who asked is using', function () {
        app()->setLocale('en');

        $english = pdfText($this->renderer->render('documents.sample', $this->data, 'en'));
        $spanish = pdfText($this->renderer->render('documents.sample', $this->data, 'es'));
        $arabic = pdfText($this->renderer->render('documents.sample', $this->data, 'ar'));

        expect($english)->toContain('Customer')->toContain('Amount')
            ->and($spanish)->toContain('Cliente')->toContain('Importe')
            ->and(containsArabic($arabic, 'العميل'))->toBeTrue();

        // The last document was Arabic; the person is still reading English.
        expect(app()->getLocale())->toBe('en');
    });

    it('keeps English and Arabic together on one page', function () {
        $data = ['title' => 'Statement / كشف الحساب', 'customer' => 'Ahmad / أحمد'] + $this->data;
        $text = pdfText($this->renderer->render('documents.sample', $data, 'en'));

        expect($text)->toContain('Statement')->and(containsArabic($text, 'كشف الحساب'))->toBeTrue();
    });

    it('carries a footer with the page number, on every page', function () {
        // No digit anywhere else in the document, so a "1 / 1" can only be the footer.
        $plain = ['lines' => [['Deposit', 'None']], 'verifyUrl' => null, 'title' => 'Receipt', 'customer' => 'Layla'];
        $one = pdfText($this->renderer->render('documents.sample', $plain, 'en'));

        expect($one)->toContain('Qistas')->and($one)->toMatch('/1\s*\/\s*1/');

        $many = $this->renderer->render('documents.sample', ['lines' => array_map(fn (int $n) => ['Line '.str_repeat('x', $n % 7), 'None'], range(1, 120))] + $plain, 'en');
        $pages = pdfPages($many);

        expect($pages)->toBeGreaterThan(1)->and(pdfText($many))->toMatch('/'.$pages.'\s*\/\s*'.$pages.'/');
    });

    it('draws English in Geist and Arabic or Urdu in IBM Plex Sans Arabic', function () {
        expect($this->renderer->render('documents.sample', $this->data, 'en'))->toContain('Geist')->not->toContain('IBMPlexSansArabic');

        foreach (['ar', 'ur'] as $language) {
            expect($this->renderer->render('documents.sample', ['title' => 'كشف الحساب'] + $this->data, $language))->toContain('IBMPlexSansArabic');
        }
    });

    it('does not let a line of text become markup', function () {
        $data = ['customer' => '<script>alert(1)</script><b>Bold</b>'] + $this->data;
        $text = pdfText($this->renderer->render('documents.sample', $data, 'en'));

        expect($text)->toContain('<script>alert(1)</script>');
    });
});

describe('the allowance', function () {
    function usedThisMonth($tenant): int
    {
        return (int) Entitlements::for($tenant)->check(Feature::PdfStatements)->used();
    }

    it('is used once a document has been made, and not before', function () {
        expect(usedThisMonth($this->tenant))->toBe(0);

        asTenant($this->tenant, fn () => $this->renderer->render('documents.sample', $this->data, 'en', quota: Feature::PdfStatements));

        expect(usedThisMonth($this->tenant))->toBe(1);
    });

    it('is left alone by a document that could not be made', function () {
        expect(fn () => asTenant($this->tenant, fn () => $this->renderer->render('documents.does-not-exist', $this->data, 'en', quota: Feature::PdfStatements)))
            ->toThrow(InvalidArgumentException::class);

        expect(usedThisMonth($this->tenant))->toBe(0);
    });

    it('is left alone when the document fails while it is being drawn', function () {
        // A view that throws part-way through, as a bad template or a missing value would.
        view()->addNamespace('broken', sys_get_temp_dir());
        file_put_contents(sys_get_temp_dir().'/boom.blade.php', '@php throw new RuntimeException("template failed"); @endphp');

        expect(fn () => asTenant($this->tenant, fn () => $this->renderer->render('broken::boom', $this->data, 'en', quota: Feature::PdfStatements)))
            ->toThrow(ViewException::class, 'template failed');

        expect(usedThisMonth($this->tenant))->toBe(0);
        @unlink(sys_get_temp_dir().'/boom.blade.php');
    });

    it('stops at the plan’s monthly limit, and the document is not handed over', function () {
        limitFreePlan(Feature::PdfStatements, 3);

        asTenant($this->tenant, function () {
            foreach (range(1, 3) as $_) {
                $this->renderer->render('documents.sample', $this->data, 'en', quota: Feature::PdfStatements);
            }

            expect(fn () => $this->renderer->render('documents.sample', $this->data, 'en', quota: Feature::PdfStatements))->toThrow(LimitReached::class);
        });

        expect(usedThisMonth($this->tenant))->toBe(3);
    });

    it('is not touched when no allowance is asked for', function () {
        asTenant($this->tenant, fn () => $this->renderer->render('documents.sample', $this->data, 'en'));

        expect(usedThisMonth($this->tenant))->toBe(0);
    });

    it('refuses to use an allowance when no workspace is active', function () {
        expect(fn () => $this->renderer->render('documents.sample', $this->data, 'en', quota: Feature::PdfStatements))->toThrow(LogicException::class);
    });
});

describe('the pieces', function () {
    it('has the fonts it needs', function (string $file) {
        expect(is_readable(resource_path('fonts/'.$file)))->toBeTrue()->and(filesize(resource_path('fonts/'.$file)))->toBeGreaterThan(10_000);
    })->with(['Geist-Regular.ttf', 'Geist-Bold.ttf', 'IBMPlexSansArabic-Regular.ttf', 'IBMPlexSansArabic-Bold.ttf']);

    it('carries the licence of each font it ships', function (string $file) {
        expect(file_get_contents(resource_path('fonts/'.$file)))->toContain('SIL Open Font License');
    })->with(['OFL-Geist.txt', 'OFL-IBMPlexSansArabic.txt']);

    it('draws a QR code as an SVG of the address, different for a different address', function () {
        $one = QrCode::svg('https://qistas.test/v/abc123');
        $two = QrCode::svg('https://qistas.test/v/xyz789');

        expect($one)->toStartWith('<?xml')->and($one)->toContain('<svg')->and($one)->toContain('<path')
            ->and($one)->not->toBe($two)->and(QrCode::svg('https://qistas.test/v/abc123'))->toBe($one);
    });

    it('puts the QR code into the document', function () {
        $with = $this->renderer->render('documents.sample', $this->data, 'en');
        $without = $this->renderer->render('documents.sample', ['verifyUrl' => null] + $this->data, 'en');

        expect(strlen($with))->toBeGreaterThan(strlen($without));
    });

    it('writes a preview of the sample for the eye to check', function () {
        $this->artisan('qistas:pdf-preview', ['language' => 'ar'])->assertSuccessful();

        expect(is_file(storage_path('app/previews/sample-ar.pdf')))->toBeTrue();
        @unlink(storage_path('app/previews/sample-ar.pdf'));
    });
});
