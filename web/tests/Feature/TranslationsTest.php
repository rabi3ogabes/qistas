<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureType;

/** Every literal __('...') string the application uses, found the same way scripts/extract_strings.py finds them. */
function usedStrings(): array
{
    $found = [];
    $roots = [app_path(), resource_path('views')];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            preg_match_all('/__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $raw) {
                $body = substr($raw, 1, -1);
                $found[] = $raw[0] === "'" ? str_replace("\\'", "'", $body) : str_replace('\\"', '"', $body);
            }
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

function appStrings(string $locale): array
{
    return json_decode(file_get_contents(lang_path("app/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $matches);
    $found = array_unique($matches[0]);
    sort($found);

    return $found;
}

// Literal on purpose: datasets are built before the application boots. The first test pins this list to the config,
// so adding a language forces its translations to be written.
$translated = ['ar', 'fr', 'es', 'ur'];
$allLocales = ['en', 'ar', 'fr', 'es', 'ur'];

it('lists exactly the languages this suite checks', function () use ($allLocales) {
    expect(config('qistas.locales'))->toEqualCanonicalizing($allLocales);
});

it('ships a translation file for every language', function (string $locale) {
    expect(lang_path("app/{$locale}.json"))->toBeFile()
        ->and(lang_path("{$locale}/units.php"))->toBeFile()
        ->and(lang_path("{$locale}/site.php"))->toBeFile();
})->with($translated);

it('translates every string the application uses', function (string $locale) {
    $missing = array_values(array_diff(usedStrings(), array_keys(appStrings($locale))));

    expect($missing)->toBe([], "Untranslated in {$locale}: ".implode(' | ', array_slice($missing, 0, 5)));
})->with($translated);

it('keeps no translation for a string the application no longer uses', function (string $locale) {
    $stale = array_values(array_diff(array_keys(appStrings($locale)), usedStrings()));

    expect($stale)->toBe([], "Stale in {$locale}: ".implode(' | ', array_slice($stale, 0, 5)));
})->with($translated);

it('never uses a string that is also the name of a language file', function () {
    // On a case-insensitive filesystem (Windows, macOS) __('Pagination') would load lang/en/pagination.php and
    // return an array, so the same code would work on Linux and crash on a developer's machine.
    $groups = array_map(fn ($file) => strtolower(basename($file, '.php')), glob(lang_path('en/*.php')));
    $clashes = array_values(array_filter(usedStrings(), fn ($string) => in_array(strtolower($string), $groups, true)));

    expect($clashes)->toBe([]);
});

it('keeps every :placeholder when translating', function (string $locale) {
    foreach (appStrings($locale) as $english => $translation) {
        expect(placeholders($translation))->toBe(placeholders($english), "[{$locale}] {$english}");
        expect(trim($translation))->not->toBe('', "[{$locale}] empty translation for: {$english}");
    }
})->with($translated);

it('has every framework message (validation, auth, passwords) in every language', function (string $locale) {
    foreach (['auth', 'passwords', 'validation', 'pagination'] as $group) {
        expect(lang_path("{$locale}/{$group}.php"))->toBeFile();
    }
})->with($translated);

describe('plural forms', function () {
    it('gives every counted feature a unit for any count, in every language', function (string $locale, int $count) {
        app()->setLocale($locale);

        foreach (Feature::cases() as $feature) {
            if ($feature->type() === FeatureType::Toggle) {
                expect($feature->unit($count))->toBe('');

                continue;
            }
            $unit = $feature->unit($count);
            expect($unit)->not->toBe('')->not->toContain('|')->not->toContain('units.');
        }
    })->with(fn () => collect(['en', 'ar', 'fr', 'es', 'ur'])->crossJoin([0, 1, 2, 3, 5, 10, 11, 99, 100, 101])->all());

    it('reads naturally in English', function () {
        app()->setLocale('en');

        expect(Feature::Customers->unit(1))->toBe('customer')->and(Feature::Customers->unit(5))->toBe('customers')
            ->and(Feature::ApiTokens->unit(1))->toBe('API token');
    });

    it('uses the right Arabic forms for one, two, a few, many and a hundred', function () {
        app()->setLocale('ar');

        expect(Feature::Customers->unit(1))->toBe('عميل')
            ->and(Feature::Customers->unit(2))->toBe('عميلين')
            ->and(Feature::Customers->unit(5))->toBe('عملاء')
            ->and(Feature::Customers->unit(11))->toBe('عميلًا')
            ->and(Feature::Customers->unit(100))->toBe('عميل');
    });

    it('words the free allowance for any number of customers in every language', function (string $locale, int $count) {
        app()->setLocale($locale);

        $sentence = trans_choice('site.free_first_customers', $count, ['count' => $count]);

        expect($sentence)->not->toBe('')->not->toContain('site.free_first_customers')->not->toContain('|');
    })->with(fn () => collect(['en', 'ar', 'fr', 'es', 'ur'])->crossJoin([1, 2, 3, 5, 11, 100])->all());
});

describe('the pages in each language', function () {
    it('speaks the reader’s language on the home page and not English', function (string $locale, string $headline) {
        $page = $this->get("/?lang={$locale}")->assertOk()->assertSee($headline);

        if ($locale !== 'en') {
            $page->assertDontSee('Every instalment, to the cent.')->assertDontSee('Start free');
        }
    })->with([
        'en' => ['en', 'Every instalment, to the cent.'],
        'ar' => ['ar', 'كل قسط، بدقة متناهية.'],
        'fr' => ['fr', 'Chaque échéance, au centime près.'],
        'es' => ['es', 'Cada cuota, al céntimo.'],
        'ur' => ['ur', 'ہر قسط، پائی پائی کے حساب سے۔'],
    ]);

    it('speaks the reader’s language on the pricing page', function (string $locale, string $text) {
        $this->get("/pricing?lang={$locale}")->assertOk()->assertSee($text);
    })->with([
        'ar' => ['ar', 'حتى 20 عميلًا'],
        'fr' => ['fr', 'Jusqu’à 20 clients'],
        'es' => ['es', 'Hasta 20 clientes'],
        'ur' => ['ur', 'زیادہ سے زیادہ 20 گاہک'],
    ]);

    it('shows the localised free-allowance sentence', function (string $locale, string $text) {
        $this->get("/?lang={$locale}")->assertSee($text);
    })->with([
        'ar' => ['ar', 'مجانًا لأول 20 عميلًا لديك، دون بطاقة.'],
        'fr' => ['fr', 'Gratuit pour vos 20 premiers clients, sans carte bancaire.'],
        'es' => ['es', 'Gratis para tus primeros 20 clientes, sin tarjeta.'],
    ]);

    it('translates framework validation messages', function (string $locale) {
        app()->setLocale($locale);

        expect(__('validation.required', ['attribute' => 'x']))->not->toBe('The x field is required.');
    })->with(['ar', 'fr', 'es', 'ur']);
});
