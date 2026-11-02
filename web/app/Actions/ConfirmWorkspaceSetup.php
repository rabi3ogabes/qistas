<?php

namespace App\Actions;

use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Audit;
use App\Support\SignupPlace;
use App\Tenancy\CurrentTenant;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The first-run card (spec 2026-11-01 §3.3): the owner gives the business its real name and confirms where it is, once.
 * The name is printed on every document, so none is made until this is done (DocumentService). The country, its
 * currency and the time zone can be changed only while the workspace has no contract and no transaction, because they
 * decide the currency of every amount and the day every payment falls on; after that the same country and zone may
 * still be sent (a screen that resends what it was given is not an error) but a different one is refused as a whole,
 * and nothing at all is saved. Callers check that [$by] is the owner and that the input is valid.
 */
final class ConfirmWorkspaceSetup
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * Saves the name and any allowed change of place and marks the workspace confirmed (the day it was first confirmed
     * stays if it already was). A country sent that is not the workspace's own sets its currency from the country table
     * and its time zone from a zone sent that differs from the current one, else the country's usual one, and counts as
     * chosen by the owner.
     *
     * @param  array{name: string, country?: string|null, timezone?: string|null}  $data
     *
     * @throws ValidationException on `country` or `timezone` when the place is locked and the value differs
     */
    public function handle(Tenant $tenant, User $by, array $data): Tenant
    {
        $name = Str::trim($data['name']);
        $country = $this->country($data['country'] ?? null);
        $zone = $this->zone($data['timezone'] ?? null);

        $countryChanges = $country !== null && $country !== strtoupper((string) $tenant->country);
        $zoneChanges = $zone !== null && $zone !== $tenant->localTimezone();

        if (($countryChanges || $zoneChanges) && ! $this->regionIsOpen($tenant)) {
            throw ValidationException::withMessages(array_filter([
                'country' => $countryChanges ? __('The country can no longer be changed because this business already has contracts or payments. Contact support to change it.') : null,
                'timezone' => $zoneChanges ? __('The time zone can no longer be changed because this business already has contracts or payments. Contact support to change it.') : null,
            ]));
        }

        // What the owner's choice of place changes, worked out before anything is written. A zone sent that is the one the
        // workspace already has is not a choice (a screen resends what it was given): with a new country it must not keep
        // the old country's zone, so only a zone that differs from the current one is honoured.
        $place = [];
        if ($countryChanges) {
            $place = [
                'country' => $country,
                'currency' => config("qistas.countries.{$country}", config('qistas.currency_default')),
                'timezone' => SignupPlace::timezone($country, $zoneChanges ? $zone : null, null),
                'country_source' => 'chosen',
            ];
        } elseif ($zoneChanges) {
            $place = ['timezone' => $zone];
        }

        return DB::transaction(function () use ($tenant, $by, $name, $place): Tenant {
            $tenant->fill(['name' => $name] + $place);

            $tenant->setup_confirmed_at ??= now();
            $tenant->save();

            Audit::record('workspace.setup_confirmed', $tenant, [
                'name' => $tenant->name,
                'country' => $tenant->country,
                'currency' => $tenant->currency,
                'timezone' => $tenant->localTimezone(),
            ], tenantId: $tenant->id, userId: $by->id);

            return $tenant;
        });
    }

    /**
     * Whether the place can still change: the workspace has no contract and no transaction. Both are read inside the
     * workspace, and money is looked at on its own, so a payment whose contract is gone still holds the place.
     */
    public function regionIsOpen(Tenant $tenant): bool
    {
        return (bool) $this->current->use($tenant, fn (): bool => ! Contract::query()->exists() && ! Transaction::query()->exists());
    }

    /** A two-letter country code in capitals, or null when none was sent. */
    private function country(mixed $given): ?string
    {
        $code = is_string($given) ? strtoupper(Str::trim($given)) : '';

        return $code === '' ? null : $code;
    }

    /** A real IANA time zone, or null when none was sent or the name is not one. */
    private function zone(mixed $given): ?string
    {
        return is_string($given) && in_array($given, DateTimeZone::listIdentifiers(), true) ? $given : null;
    }
}
