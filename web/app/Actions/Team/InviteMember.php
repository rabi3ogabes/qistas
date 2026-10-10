<?php

namespace App\Actions\Team;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes an invitation link into the business, for one role. The link carries a random secret shown only now; the
 * database keeps its SHA-256 fingerprint. A waiting invitation holds a place under the plan's people limit.
 */
final class InviteMember
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $details
     * @return array{invitation: TenantInvitation, url: string}
     */
    public function handle(Tenant $tenant, User $by, string $role, array $details = []): array
    {
        $byRole = $by->roleIn($tenant->id);
        if (! TeamRules::canManage($byRole)) {
            TeamRules::deny();
        }

        $given = TenantRole::tryFrom($role);
        if ($given === null || $given === TenantRole::Owner) {
            throw ValidationException::withMessages(['role' => __('Choose a manager, accountant, collector or viewer.')]);
        }
        if (! in_array($given, TeamRules::assignable($byRole), true)) {
            TeamRules::deny();
        }

        return DB::transaction(fn () => $this->current->use($tenant, function () use ($tenant, $by, $given, $details): array {
            // Serialise per business, so two invitations at once cannot both take the last place.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            Entitlements::for($tenant)->assertCanCreate(Feature::Members);

            $token = Str::random(48);
            $invitation = (new TenantInvitation)->fill([
                'name' => $this->clean($details['name'] ?? null),
                'email' => $this->clean($details['email'] ?? null),
                'phone' => $this->clean($details['phone'] ?? null),
            ])->forceFill([
                'role' => $given,
                'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $by->id,
                'expires_at' => now()->addDays(TenantInvitation::DAYS),
            ]);
            $invitation->save();

            Audit::record('team.member_invited', $invitation, ['role' => $given->value], tenantId: $tenant->id, userId: $by->id);

            return ['invitation' => $invitation, 'url' => route('invitation.show', ['token' => $token])];
        }));
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
