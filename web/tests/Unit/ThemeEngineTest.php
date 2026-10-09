<?php

declare(strict_types=1);

use App\Theme\Color;
use App\Theme\ThemeEngine;

/*
| The colour rules of the theme engine (docs/THEME_ENGINE.md), ported from the reference implementation
| brand/shared/qistas-theme.js. The reference's answers are recorded in shared/theme-vectors.json (made by
| brand/tools/make-theme-vectors.cjs); PHP must give the same palette token for token, because the Android app and the
| website both show whatever the server resolves.
*/

function themeVectors(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/shared/theme-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
}

dataset('theme cases', fn () => collect(themeVectors()['cases'])->mapWithKeys(fn ($case) => [$case['name'] => [$case]])->all());

describe('the factory look', function () {
    it('is exactly the reference engine’s base theme, light and dark', function () {
        expect(ThemeEngine::BASE)->toBe(themeVectors()['base']);
    });

    it('resolves to itself and passes every contrast check', function () {
        expect(ThemeEngine::resolve([]))->toBe(ThemeEngine::BASE)
            ->and(array_filter(ThemeEngine::validate(ThemeEngine::BASE), fn ($check) => ! $check['pass']))->toBe([]);
    });

    it('has the 26 tokens, in both modes', function () {
        expect(ThemeEngine::BASE['light'])->toHaveCount(26)->and(array_keys(ThemeEngine::BASE['light']))->toBe(array_keys(ThemeEngine::BASE['dark']));
    });
});

describe('a brand choice', function () {
    it('resolves to the reference engine’s palette, token for token', function (array $case) {
        expect(ThemeEngine::resolve($case['pins']))->toBe($case['resolved']);
    })->with('theme cases');

    it('resolves, before any repair, to the reference engine’s raw palette', function (array $case) {
        expect(ThemeEngine::resolve($case['pins'], repair: false))->toBe($case['unrepaired']);
    })->with('theme cases');

    it('fails the same contrast checks the reference fails before repair', function (array $case) {
        $failing = collect(ThemeEngine::validate(ThemeEngine::resolve($case['pins'], repair: false)))
            ->reject(fn ($check) => $check['pass'])->map(fn ($check) => $check['mode'].':'.$check['fg'].'/'.$check['bg'])->values()->all();

        expect($failing)->toBe($case['failingBeforeRepair']);
    })->with('theme cases');

    it('always ends up readable, unless the canvas itself contradicts the cards', function (array $case) {
        $bad = array_filter(ThemeEngine::validate(ThemeEngine::resolve($case['pins'])), fn ($check) => ! $check['pass'] && $check['blocking']);

        // One text colour cannot be readable on a near-black canvas and on white cards at once. The reference engine
        // leaves exactly those pairs failing, so a look like that has to be refused (the Appearance studio only takes a
        // light canvas); every other look is repaired to the last pair.
        $contradiction = str_contains($case['name'], 'black on black');

        expect(array_values(array_unique(array_column($bad, 'bg'))))->toBe($contradiction ? ['bg'] : []);
    })->with('theme cases');

    it('gets a whole dark mode from the primary colour alone', function () {
        $dark = ThemeEngine::resolve(['light' => ['primary' => '#0F5132']])['dark'];

        // The factory dark canvas is a navy; a green primary gives a green-black one.
        expect($dark['bg'])->not->toBe(ThemeEngine::BASE['dark']['bg'])->and(Color::hexToHsl($dark['bg'])[0])->toBeGreaterThan(120)->toBeLessThan(170);
    });

    it('repairs a hostile palette and says what it moved', function () {
        // Black text on a black button, and text the colour of the canvas: nothing wrong with the backgrounds, so every pair can be fixed.
        $pinned = ThemeEngine::resolve(['light' => ['primary' => '#000000', 'onPrimary' => '#000000', 'ink' => '#F7F3EA']], repair: false);
        $repair = ThemeEngine::autoFix($pinned);

        expect(array_filter(ThemeEngine::validate($pinned), fn ($check) => ! $check['pass']))->not->toBeEmpty()
            ->and(array_filter(ThemeEngine::validate($repair['tokens']), fn ($check) => ! $check['pass']))->toBe([])
            ->and($repair['changed'])->not->toBeEmpty()->and($repair['changed'][0])->toHaveKeys(['mode', 'token', 'from', 'to']);
    });
});

describe('what is refused', function () {
    it('takes only #RRGGBB colours, never something that could break out of a stylesheet', function (string $value) {
        expect(fn () => ThemeEngine::resolve(['light' => ['primary' => $value]]))->toThrow(InvalidArgumentException::class);
    })->with(['red', '#FFF', '#GGGGGG', 'red;} body{display:none', '#0B1F44;', 'url(javascript:alert(1))', '', '#0B1F44 ', "#0B1F44\n"]);

    it('knows only the 26 tokens and the two modes', function () {
        expect(fn () => ThemeEngine::resolve(['light' => ['bogus' => '#000000']]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ThemeEngine::resolve(['sepia' => ['primary' => '#000000']]))->toThrow(InvalidArgumentException::class);
    });

    it('writes colours in capitals, whatever case they came in', function () {
        expect(ThemeEngine::resolve(['light' => ['accent' => '#a0522d']])['light']['accent'])->toBe('#A0522D');
    });
});

describe('the colour maths', function () {
    it('matches the reference engine', function () {
        $v = themeVectors();

        foreach ($v['colour'] as $c) {
            expect(Color::contrast($c['a'], $c['b']))->toEqualWithDelta($c['result'], 1e-9);
        }
        foreach ($v['readableOn'] as $c) {
            expect(Color::readableOn($c['bg']))->toBe($c['result']);
        }
        foreach ($v['ensureContrast'] as $c) {
            expect(Color::ensureContrast($c['fg'], $c['bg'], $c['min']))->toBe($c['result']);
        }
        foreach ($v['mix'] as $c) {
            expect(Color::mix($c['a'], $c['b'], $c['t']))->toBe($c['result']);
        }
        foreach ($v['lighten'] as $c) {
            expect(Color::lighten($c['hex'], $c['amt']))->toBe($c['result']);
        }
    });

    it('knows black on white is 21 to 1', function () {
        expect(Color::contrast('#000000', '#FFFFFF'))->toEqualWithDelta(21.0, 1e-9)->and(Color::contrast('#777777', '#777777'))->toBe(1.0);
    });
});
