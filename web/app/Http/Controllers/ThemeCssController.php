<?php

namespace App\Http\Controllers;

use App\Models\AppearanceEvent;
use App\Theme\Appearance;
use App\Theme\Color;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The chosen look as CSS: the theme's colour variables (--q-*) for light, for dark, and for a device set to dark, and
 * nothing else. Every value has passed the theme engine and is checked again here as #RRGGBB, so nothing an admin typed
 * can become a rule. Linked after the site's own styles, so it wins; empty while the factory look is on. With ?e=<event
 * id>.<revision> it is that event's palette over the published look.
 */
final class ThemeCssController
{
    public function __invoke(Request $request, Appearance $appearance): Response
    {
        $look = $appearance->live();
        $tokens = $look->tokens();
        $custom = $look->hasCustomColours();
        $wanted = (string) $request->query('e', '');
        $exact = (string) $request->query('v') === (string) $look->version();
        $label = 'version '.$look->version();

        if ($wanted !== '') {
            // Only a scheduled event with colours of its own has a palette; anything else is the usual look, kept briefly.
            $event = preg_match('/\A([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(\d{1,9})\z/i', $wanted, $m) === 1
                ? AppearanceEvent::query()->whereKey(strtolower($m[1]))->where('status', 'scheduled')->first()
                : null;

            if ($event !== null && ($event->pins()['light'] ?? []) !== []) {
                $tokens = $appearance->dressing($event)['tokens'];
                $custom = true;
                $exact = $exact && (int) $m[2] === $event->revision;
                $label .= ', event revision '.$event->revision;
            } else {
                $exact = false;
            }
        }

        $css = '/* Qistas: the look chosen in the admin, '.$label.". Colour variables only. */\n";

        if ($custom) {
            $dark = $this->declarations($tokens['dark'], '        ');
            [$r, $g, $b] = Color::hexToRgb($tokens['light']['primary']);

            $css .= ":root {\n".$this->declarations($tokens['light'], '    ')
                ."    --q-shadow-1: 0 1px 0 rgb({$r} {$g} {$b} / .04), 0 8px 24px -12px rgb({$r} {$g} {$b} / .16);\n"
                ."    --q-shadow-2: 0 1px 0 rgb({$r} {$g} {$b} / .05), 0 24px 56px -24px rgb({$r} {$g} {$b} / .30);\n"
                ."}\n\n"
                .":root[data-q-mode=\"dark\"] {\n".$this->declarations($tokens['dark'], '    ')."}\n\n"
                ."@media (prefers-color-scheme: dark) {\n    :root:not([data-q-mode=\"light\"]) {\n".$dark."    }\n}\n";
        }

        // The page links /theme.css?v=<version>(&e=<event>.<revision>): that exact address never changes, so it is cached
        // for a year. Any other address (an old version or revision, none) is answered with the current look, briefly.
        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $exact ? 'public, max-age=31536000, immutable' : 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param  array<string, string>  $tokens */
    private function declarations(array $tokens, string $indent): string
    {
        $out = '';

        foreach ($tokens as $token => $value) {
            if (Color::isHex($value)) {
                $out .= $indent.'--q-'.Str::kebab($token).': '.strtoupper($value).";\n";
            }
        }

        return $out;
    }
}
