<?php

namespace App\Actions;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\LimitReached;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/** Adds a customer to a workspace, if its plan still has room. The one place customers are created. */
final class CreateCustomer
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array<string, mixed>  $data  validated customer fields (see App\Http\Requests\CustomerRequest)
     *
     * @throws FeatureLocked|LimitReached
     */
    public function handle(Tenant $tenant, array $data, ?User $by = null): Customer
    {
        return DB::transaction(fn () => $this->current->use($tenant, function () use ($tenant, $data, $by): Customer {
            // Lock the workspace row first. Two simultaneous requests queue here, so the second counts
            // customers only after the first has committed and cannot slip past the limit. (PostgreSQL;
            // SQLite has no row locks but also only one writer at a time.)
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            Entitlements::for($tenant)->assertCanCreate(Feature::Customers);

            $customer = new Customer($data);
            $customer->created_by_user_id = $by?->id;
            $customer->save();

            if (! empty($data['tags'])) {
                Entitlements::for($tenant)->assertEnabled(Feature::CustomerTags);
                $customer->syncTags($data['tags']);
            }

            return $customer;
        }));
    }
}
