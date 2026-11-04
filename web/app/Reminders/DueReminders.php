<?php

namespace App\Reminders;

use App\Documents\BusinessProfile;
use App\Models\Tenant;
use App\Reports\DashboardMetrics;
use App\Support\Format;
use App\Support\InLanguage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The remind-all checklist (Win Plan PP9): who pays today (or who is late), each with the number WhatsApp wants and the
 * reminder already written in the language asked for, in the business's own words where it has them.
 */
final class DueReminders
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly ReminderTemplates $templates,
    ) {}

    /**
     * @param  string  $scope  due (who pays today, as the dashboard lists them) or late (oldest first)
     * @return list<array<string, mixed>>
     */
    public function rows(Tenant $tenant, string $scope, string $language, ?CarbonInterface $now = null): array
    {
        $now ??= $tenant->localTime(now());
        $late = $scope === 'late';
        $rows = $late ? $this->metrics->lateList($tenant, $now) : $this->metrics->dueTodayList($tenant, $now);
        $body = $this->templates->body($tenant, $late ? 'reminder_late' : 'reminder_due', $language);
        $currency = (string) $tenant->currency;
        $country = (string) $tenant->country;
        $business = self::signature($tenant, $language);

        return InLanguage::run($language, fn () => array_map(fn (array $row): array => $row + [
            'whatsapp' => WhatsApp::number((string) $row['customer_phone'], $country),
            // Trimmed, so a message signed with no name ends on its last word rather than a space.
            'message' => trim(ReminderTemplates::fill($body, [
                'name' => $row['customer_name'],
                'amount' => Format::money((string) $row['amount_due'], $currency),
                'date' => Carbon::parse((string) $row['due_date'])->translatedFormat('j F Y'),
                'reference' => $row['contract_reference'],
                'business' => $business,
            ])),
        ], $rows));
    }

    /**
     * The name a reminder is signed with. Until the owner names the business (the first-run card), the workspace's name
     * is the sign-up's placeholder ("Business of Layla", the owner's own first name) and must not reach a customer: the
     * name the owner printed on the documents is used if there is one, else none.
     */
    private static function signature(Tenant $tenant, string $language): string
    {
        return $tenant->isSetUp() ? (string) $tenant->name : (string) BusinessProfile::for($tenant)->printedName($language);
    }
}
