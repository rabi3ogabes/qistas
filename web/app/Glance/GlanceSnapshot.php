<?php

namespace App\Glance;

use App\Models\Tenant;
use App\Notifications\Alerts\AlertQueries;
use App\Notifications\Alerts\DailyDigest;
use App\Reports\DashboardMetrics;
use App\Settings\TenantSettings;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the phone's home-screen widget shows: today's money in a glance, on the workspace's own day, counted exactly as the
 * morning summary counts it (the same queries, the same "today", the same late limit), and the next three instalments.
 *
 * It holds counts, amounts and references. A customer's name is added only when the caller says names are allowed; a phone
 * number, a national ID and every other detail of a customer are never part of it, so a locked screen shows nothing more.
 */
final class GlanceSnapshot
{
    /** How many instalments the widget lists. */
    public const NEXT = 3;

    /**
     * A handful of queries however many instalments there are: the setting, the five counts, and at most two short lists.
     *
     * @return array{enabled: true, currency: string, due_count: int, due_amount: string, late_count: int, late_amount: string,
     *     next: list<array{reference: string, amount: string, due_on: string, name?: string}>, generated_at: string}
     */
    public static function for(Tenant $tenant, bool $names, ?CarbonInterface $now = null): array
    {
        // A copy, because localTime() moves the zone of the moment it is handed, and that is the caller's own.
        $moment = CarbonImmutable::instance($now ?? now());
        // The morning summary's own rule: "today" is the workspace's day, and the late limit is the workspace's setting.
        $today = $tenant->localTime($moment);
        $lateLimit = (int) TenantSettings::for($tenant)->get(DailyDigest::LATE_LIMIT_SETTING);

        $totals = app(CurrentTenant::class)->use($tenant, fn (): array => app(AlertQueries::class)->totals($today, $lateLimit));

        // Those due today (oldest first), then those coming this week, until there are three. Nothing coming is asked
        // for when today alone fills the list.
        $metrics = app(DashboardMetrics::class);
        $rows = $metrics->dueTodayList($tenant, $today, self::NEXT);
        if (count($rows) < self::NEXT) {
            $rows = [...$rows, ...$metrics->comingList($tenant, $today, self::NEXT - count($rows))];
        }

        return [
            'enabled' => true,
            'currency' => (string) $tenant->currency,
            'due_count' => $totals['due_count'],
            'due_amount' => $totals['due_amount'],
            'late_count' => $totals['late_count'],
            'late_amount' => $totals['late_amount'],
            'next' => array_map(fn (array $row): array => self::entry($row, $names), $rows),
            'generated_at' => $moment->utc()->toIso8601String(),
        ];
    }

    /**
     * One instalment as the widget shows it: which contract, what is still owed, and the day it falls due.
     *
     * @param  array<string, mixed>  $row
     * @return array{reference: string, amount: string, due_on: string, name?: string}
     */
    private static function entry(array $row, bool $names): array
    {
        $entry = ['reference' => (string) $row['contract_reference'], 'amount' => (string) $row['amount_due'], 'due_on' => (string) $row['due_date']];

        return $names ? $entry + ['name' => (string) $row['customer_name']] : $entry;
    }
}
