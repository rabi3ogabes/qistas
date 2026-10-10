<?php

namespace App\Actions\Investors;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Entitlements\LimitReached;
use App\Models\Investor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames an investor, changes their registration, commission or notes, or archives them (an archived investor funds
 * no new contracts; everything they funded stays theirs). The business's own capital is never archived and pays no
 * commission to itself.
 */
final class UpdateInvestor
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array<string, mixed>  $data  validated (App\Http\Requests\InvestorRequest)
     *
     * @throws ValidationException|FeatureLocked|FeatureUnavailable|LimitReached
     */
    public function handle(Investor $investor, array $data, ?User $by = null): Investor
    {
        $tenant = $investor->tenant;

        return DB::transaction(fn () => $this->current->use($tenant, function () use ($investor, $tenant, $data, $by): Investor {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $entitlements = Entitlements::for($tenant);
            $entitlements->assertEnabled(Feature::Investors);

            if ($investor->is_main && (($data['archived'] ?? false) === true || isset($data['commission_percent']) && (float) $data['commission_percent'] > 0)) {
                throw ValidationException::withMessages(['investor' => __('The business’s own capital cannot be archived and pays no commission.')]);
            }

            $investor->fill(array_intersect_key($data, array_flip(['name', 'commercial_registration', 'commission_percent', 'notes'])));
            if (array_key_exists('archived', $data)) {
                $restoring = $data['archived'] === false && $investor->isArchived();
                if ($restoring) {
                    // Back among the investors who can fund contracts: it counts against the plan again.
                    $entitlements->assertCanCreate(Feature::Investors);
                }
                $investor->forceFill(['archived_at' => $data['archived'] ? ($investor->archived_at ?? now()) : null]);
            }

            $changed = array_keys($investor->getDirty());
            $investor->save();

            if ($changed !== []) {
                Audit::record('investor.updated', $investor, ['fields' => $changed], tenantId: $tenant->id, userId: $by?->id);
            }

            return $investor;
        }));
    }
}
