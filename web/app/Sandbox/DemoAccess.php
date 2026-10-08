<?php

namespace App\Sandbox;

use App\Actions\RegisterTenantOwner;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Try the demo": a throw-away account for whoever presses a demo button, so a visitor can look around the
 * dashboard or the app without registering and without typing a password.
 *
 *  - "admin": the owner of a workspace on the Pro plan, with every feature.
 *  - "user":  the owner of a workspace on the Free plan, with its limits.
 *
 * Each press makes a NEW account with its own workspace and sample data, so visitors never see one another's
 * changes. The account has a random password nobody knows, is never platform staff, expires after a few hours and
 * is then deleted with everything it owns (the next press clears whatever has expired). The number of demo accounts
 * is capped and each address is rate limited (see config qistas.demo_login), so the demo cannot be used to fill
 * the database.
 */
final class DemoAccess
{
    /** The demo e-mail domain: reserved (.test), so no message can ever leave for it. */
    public const DOMAIN = 'demo.qistas.test';

    public function __construct(
        private readonly RegisterTenantOwner $register,
        private readonly SampleBusiness $sample,
    ) {}

    /** Demo sign-in is offered when switched on, and always on a site that is itself a demo. */
    public static function enabled(): bool
    {
        return (bool) config('qistas.demo_login.enabled') || (bool) config('qistas.demo');
    }

    /** @return list<array{key: string, label: string, description: string, plan: string}> */
    public function personas(): array
    {
        return [
            ['key' => 'admin', 'label' => __('Enter as admin'), 'description' => __('Every feature, on the Pro plan'), 'plan' => 'pro'],
            ['key' => 'user', 'label' => __('Enter as user'), 'description' => __('The Free plan, with its limits'), 'plan' => 'free'],
        ];
    }

    /**
     * Make the account, its workspace and its sample data.
     *
     * @throws DemoBusy when the site already holds as many demo accounts as it allows
     */
    public function start(string $persona): User
    {
        $chosen = collect($this->personas())->firstWhere('key', $persona);
        abort_unless(self::enabled() && $chosen !== null, 404);

        $this->prune();

        if (User::query()->whereNotNull('demo_expires_at')->count() >= (int) config('qistas.demo_login.max_accounts')) {
            throw new DemoBusy;
        }

        return DB::transaction(function () use ($persona, $chosen): User {
            $user = $this->register->handle([
                'name' => $persona === 'admin' ? 'Demo Admin' : 'Demo User',
                'email' => 'demo-'.Str::lower(Str::random(14)).'@'.self::DOMAIN,
                'password' => Str::random(48),
                'business_name' => 'Al-Fares Electronics',
                'country' => 'SA',
                'locale' => app()->getLocale(),
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
                'demo_expires_at' => now()->addHours((int) config('qistas.demo_login.hours')),
            ])->save();

            $tenant = $user->tenants()->sole();
            $tenant->forceFill(['is_demo' => true])->save();
            $tenant->subscribeTo(Plan::query()->where('key', $chosen['plan'])->firstOrFail());

            $this->sample->populate($tenant, $user);

            Audit::record('demo.started', $tenant, ['persona' => $persona], tenantId: $tenant->id, userId: $user->id);

            return $user;
        });
    }

    /** Delete the demo accounts whose time is up, with everything they own. Returns how many were removed. */
    public function prune(int $limit = 100): int
    {
        $expired = User::query()->whereNotNull('demo_expires_at')->where('demo_expires_at', '<', now())->limit($limit)->get();

        $expired->each(fn (User $user) => $this->forget($user));

        return $expired->count();
    }

    /** Delete one demo account now: its workspace and all its data, its sessions and its tokens. */
    public function forget(User $user): void
    {
        // Only ever a demo account. Anything else is left exactly as it is.
        if ($user->demo_expires_at === null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            Tenant::query()->where('owner_user_id', $user->id)->where('is_demo', true)->each(fn (Tenant $tenant) => $tenant->delete());

            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
