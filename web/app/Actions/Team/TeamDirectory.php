<?php

namespace App\Actions\Team;

use App\Models\Tenant;
use App\Tenancy\TenantRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The people in a business with their role and when they joined: the owner first, then in the order they joined. */
final class TeamDirectory
{
    /** @return list<array{id: string, name: string, email: string, role: TenantRole, joined_at: ?string}> */
    public static function members(Tenant $tenant): array
    {
        $rows = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenant->id)
            ->orderByRaw("case when tenant_users.role = 'owner' then 0 else 1 end")
            ->orderBy('tenant_users.created_at')->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.email', 'tenant_users.role', 'tenant_users.created_at']);

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            'role' => TenantRole::from((string) $row->role),
            'joined_at' => $row->created_at === null ? null : Carbon::parse((string) $row->created_at)->toIso8601String(),
        ])->values()->all();
    }
}
