<?php

namespace App\Support;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;

/**
 * How a workspace shows a date (Win Plan CT26): the Gregorian day alone, or with its Umm al-Qura day beside it, or
 * the Hijri day first. The owner chooses on the tools page once the platform has Hijri dates on; with the switch off
 * every workspace is back to the Gregorian day alone. Read once per request or job (a scoped service), and forgotten
 * when the choice changes. What is stored is always the Gregorian day.
 */
final class Dates
{
    public const SETTING = 'dates.hijri';

    public const MODES = ['off', 'beside', 'first'];

    /** @var array<string, string> mode by workspace id */
    private array $modes = [];

    public static function register(SettingsRegistry $registry): void
    {
        $registry->register(new SettingDefinition(self::SETTING, Feature::HijriDates, 'select', 'off', [],
            fn () => __('Hijri dates'), fn () => __('Umm al-Qura, on every page, document and date you pick. What is stored stays Gregorian.'),
            ['off' => fn () => __('Gregorian only'), 'beside' => fn () => __('Hijri beside the Gregorian date'), 'first' => fn () => __('Hijri first')]));
    }

    /** off, beside or first, for [$tenant] or the active workspace (off when there is none). */
    public function mode(?Tenant $tenant = null): string
    {
        $tenant ??= app(CurrentTenant::class)->get();
        if ($tenant === null) {
            return 'off';
        }

        return $this->modes[$tenant->id] ??= Entitlements::for($tenant)->check(Feature::HijriDates)->enabled()
            ? (string) TenantSettings::for($tenant)->get(self::SETTING)
            : 'off';
    }

    public function forget(): void
    {
        $this->modes = [];
    }

    /**
     * The day as it leads ([$style], e.g. "1 Feb 2026", or the Hijri day when it comes first) and the other calendar's
     * day, or null when there is none to show.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function parts(CarbonInterface $date, string $style = 'j M Y'): array
    {
        $mode = app(self::class)->mode();
        $gregorian = $date->translatedFormat($style);
        $hijri = $mode === 'off' ? null : Hijri::format($date);

        return match (true) {
            $hijri === null => [$gregorian, null],
            $mode === 'first' => [$hijri, $gregorian],
            default => [$gregorian, $hijri],
        };
    }

    /** The day in one line, for sentences and documents: "1 Feb 2026 (13 Shaban 1447 AH)". Empty for no date. */
    public static function day(?CarbonInterface $date, string $style = 'j M Y'): string
    {
        if ($date === null) {
            return '';
        }
        [$lead, $other] = self::parts($date, $style);

        return $other === null ? $lead : __(':date (:other)', ['date' => $lead, 'other' => $other]);
    }
}
