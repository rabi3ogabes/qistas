<?php

namespace App\Domain\Investors;

use App\Models\Investor;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * The business's own capital: the investor that funds every contract nobody else funds. Made the first time anything
 * needs it, once per workspace (a unique index backs this), and at that moment it takes on every contract made before
 * investors existed, with all their payments, so its figures are right from the first day they are shown.
 */
final class MainInvestor
{
    /** Its name until the owner renames it; shown in each person's language. */
    public const NAME = 'Own capital';

    public static function for(Tenant $tenant): Investor
    {
        return app(CurrentTenant::class)->use($tenant, function () use ($tenant): Investor {
            $main = Investor::query()->where('is_main', true)->first();
            if ($main !== null) {
                return $main;
            }

            return DB::transaction(function () use ($tenant): Investor {
                // Two first requests at once: the second waits here and then finds the first one's investor.
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
                $main = Investor::query()->where('is_main', true)->first();
                if ($main !== null) {
                    return $main;
                }

                $main = (new Investor)->forceFill(['name' => self::NAME, 'currency' => $tenant->currency, 'is_main' => true]);
                $main->save();

                app(InvestorLedger::class)->catchUp($main);

                return $main;
            });
        });
    }

    /** How to call an investor on screen: the main one keeps its translated name until the owner gives it another. */
    public static function displayName(Investor $investor): string
    {
        return $investor->is_main && $investor->name === self::NAME ? __('Own capital') : $investor->name;
    }
}
