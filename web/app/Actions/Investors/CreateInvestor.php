<?php

namespace App\Actions\Investors;

use App\Domain\Investors\MainInvestor;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Entitlements\LimitReached;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/** Adds a partner who funds contracts (Win Plan PP3), with the money they start with as their first deposit. */
final class CreateInvestor
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array{name: string, commercial_registration?: ?string, commission_percent?: ?string, notes?: ?string, opening_capital?: ?string}  $data
     *
     * @throws FeatureLocked|FeatureUnavailable|LimitReached
     */
    public function handle(Tenant $tenant, array $data, ?User $by = null): Investor
    {
        // The business's own capital exists first, so the plan's allowance counts it (Free keeps that one only).
        MainInvestor::for($tenant);

        return DB::transaction(fn () => $this->current->use($tenant, function () use ($tenant, $data, $by): Investor {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            Entitlements::for($tenant)->assertCanCreate(Feature::Investors);

            $investor = (new Investor)->fill([
                'name' => $data['name'],
                'commercial_registration' => $data['commercial_registration'] ?? null,
                'commission_percent' => $data['commission_percent'] ?? '0',
                'notes' => $data['notes'] ?? null,
            ])->forceFill(['currency' => $tenant->currency, 'is_main' => false]);
            $investor->save();

            $opening = $data['opening_capital'] ?? null;
            if ($opening !== null && Money::isPositive(Money::parse($opening))) {
                (new InvestorEntry)->forceFill([
                    'investor_id' => $investor->id,
                    'type' => 'deposit',
                    'amount' => Money::add(Money::parse($opening), '0', 2),
                    'note' => __('Opening capital'),
                    'occurred_on' => today()->format('Y-m-d'),
                    'created_by_user_id' => $by?->id,
                ])->save();
            }

            Audit::record('investor.created', $investor, ['name' => $investor->name], tenantId: $tenant->id, userId: $by?->id);

            return $investor;
        }));
    }
}
