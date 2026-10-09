<?php

namespace App\Reports;

use App\Models\CronRun;
use App\Models\Subscription;
use App\Sandbox\DemoAccess;
use Illuminate\Support\Facades\DB;

/**
 * The platform at a glance, for the admin area: how many real businesses and people use it, on which plan, how many
 * signed up lately, and whether the things a live site depends on are set up.
 *
 * This is platform-admin code, so it reads across every workspace on purpose; it only COUNTS. It never returns a
 * workspace's names, customers or money. Throw-away demo accounts, an admin's test workspace and platform staff are
 * left out of every figure, so the numbers are the real business.
 */
final class PlatformOverview
{
    /**
     * @return array{
     *     workspaces: int, free: int, pro: int, other: int, people: int, signups_week: int, signups_prev_week: int,
     *     active_contracts: int, customers: int
     * }
     */
    public function figures(): array
    {
        $real = fn () => DB::table('tenants')->where('is_test', false)->where('is_demo', false);

        // A workspace is on a paid plan only while its subscription is in force and inside its period (plus the grace
        // days); otherwise it is on Free again, exactly as Tenant::currentPlan() decides. No row at all is Free too.
        $byPlan = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->where('tenants.is_test', false)->where('tenants.is_demo', false)
            ->whereIn('subscriptions.status', Subscription::IN_FORCE)
            ->where(fn ($query) => $query->whereNull('subscriptions.current_period_end')
                ->orWhere('subscriptions.current_period_end', '>', now()->subDays((int) config('qistas.billing.grace_days'))))
            ->where('plans.key', '!=', 'free')
            ->selectRaw('plans.key as plan, COUNT(*) as total')
            ->groupBy('plans.key')
            ->pluck('total', 'plan');

        $workspaces = (int) $real()->count();
        $pro = (int) ($byPlan['pro'] ?? 0);
        $other = (int) $byPlan->except('pro')->sum();
        $free = max(0, $workspaces - $pro - $other);

        $people = fn () => DB::table('users')->whereNull('platform_role')->whereNull('demo_expires_at');
        $week = now()->subDays(7);

        return [
            'workspaces' => $workspaces,
            'free' => $free,
            'pro' => $pro,
            'other' => $other,
            'people' => (int) $people()->count(),
            'signups_week' => (int) $people()->where('created_at', '>=', $week)->count(),
            'signups_prev_week' => (int) $people()->where('created_at', '>=', now()->subDays(14))->where('created_at', '<', $week)->count(),
            'active_contracts' => (int) DB::table('contracts')
                ->join('tenants', 'tenants.id', '=', 'contracts.tenant_id')
                ->where('tenants.is_test', false)->where('tenants.is_demo', false)
                ->where('contracts.status', 'active')->count(),
            'customers' => (int) DB::table('customers')
                ->join('tenants', 'tenants.id', '=', 'customers.tenant_id')
                ->where('tenants.is_test', false)->where('tenants.is_demo', false)
                ->whereNull('customers.deleted_at')->count(),
        ];
    }

    /**
     * What a live site needs, each with how it stands: ok, warn or off. Words are written for the person reading.
     *
     * @return list<array{key: string, status: string, title: string, detail: string}>
     */
    public function health(): array
    {
        $checks = [];

        $checks[] = [
            'key' => 'database',
            'status' => 'ok',
            'title' => __('Database'),
            'detail' => __('Connected (:driver).', ['driver' => DB::connection()->getDriverName()]),
        ];

        $mailer = (string) config('mail.default');
        $sends = ! in_array($mailer, ['log', 'array'], true);
        $checks[] = [
            'key' => 'mail',
            'status' => $sends ? 'ok' : 'warn',
            'title' => __('E-mail'),
            'detail' => $sends
                ? __('Sending through :mailer.', ['mailer' => $mailer])
                : __('Not sending: verification and password-reset e-mails only go to the log. Set the SMTP details to switch it on.'),
        ];

        $production = app()->isProduction();
        $debug = (bool) config('app.debug');
        $checks[] = [
            'key' => 'environment',
            'status' => $production && $debug ? 'warn' : 'ok',
            'title' => __('Environment'),
            'detail' => $production && $debug
                ? __('Debug mode is on in production. Turn APP_DEBUG off.')
                : __('Running in :env mode.', ['env' => (string) config('app.env')]),
        ];

        $demo = DemoAccess::enabled();
        $accounts = (int) DB::table('users')->whereNotNull('demo_expires_at')->count();
        $expired = (int) DB::table('users')->whereNotNull('demo_expires_at')->where('demo_expires_at', '<', now())->count();
        $checks[] = [
            'key' => 'demo',
            'status' => $demo ? 'ok' : 'off',
            'title' => __('Demo sign-in'),
            'detail' => $demo
                ? __(':count of :max demo accounts in use (:expired expired and waiting to be cleared).', ['count' => $accounts, 'max' => (int) config('qistas.demo_login.max_accounts'), 'expired' => $expired])
                : __('Off. The sign-in page has no demo buttons.'),
        ];

        $checks[] = $this->cronCheck();

        return $checks;
    }

    /**
     * Whether scheduled work is running: off until a secret exists and a first run has happened (nothing is wrong
     * yet), ok while the last run is under 15 minutes old and went well, warn when it has gone quiet or failed.
     *
     * @return array{key: string, status: string, title: string, detail: string}
     */
    private function cronCheck(): array
    {
        $check = fn (string $status, string $detail): array => ['key' => 'cron', 'status' => $status, 'title' => __('Scheduled work'), 'detail' => $detail];

        if ((string) config('qistas.cron.secret') === '') {
            return $check('off', __('Not set up yet. Set CRON_SECRET, and the CRON_URL and CRON_SECRET repository secrets, to run scheduled work every five minutes.'));
        }

        $last = CronRun::query()->orderByDesc('started_at')->first();

        if ($last === null) {
            return $check('off', __('Waiting for the first run. The Scheduler workflow on GitHub calls this site every five minutes once its secrets are set.'));
        }

        $when = $last->started_at->utc()->format('Y-m-d H:i').' UTC';

        if ($last->outcome === 'failed') {
            return $check('warn', __('The last run failed (:when). Run php artisan qistas:cron-status for what went wrong.', ['when' => $when]));
        }

        if ($last->started_at->lt(now()->subMinutes(15))) {
            return $check('warn', __('The last run was over 15 minutes ago (:when). Check the Scheduler workflow on GitHub.', ['when' => $when]));
        }

        return $check('ok', __('Last ran :when.', ['when' => $when]));
    }
}
