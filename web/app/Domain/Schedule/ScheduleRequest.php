<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

final readonly class ScheduleRequest
{
    /**
     * @param  list<array{due_date: string, amount: string}>|null  $customSchedule  the shop's own rows, for frequency "custom"
     */
    public function __construct(
        public string $principal,
        public string $downPayment,
        public string $markupType,   // none | fixed | percent
        public string $markupValue,
        public int $count,
        public string $frequency,    // see ScheduleGenerator::FREQUENCIES, or custom
        public string $firstDueDate, // Y-m-d
        public ?array $customSchedule = null,
    ) {}

    /** @param array<string,mixed> $a snake_case input as used by the API and the shared vectors */
    public static function fromArray(array $a): self
    {
        $custom = null;
        if (is_array($a['custom_schedule'] ?? null)) {
            $custom = [];
            foreach (array_values($a['custom_schedule']) as $row) {
                $custom[] = [
                    'due_date' => is_array($row) ? (string) ($row['due_date'] ?? '') : '',
                    'amount' => is_array($row) ? (string) ($row['amount'] ?? '') : '',
                ];
            }
        }

        return new self(
            principal: (string) ($a['principal'] ?? ''),
            downPayment: (string) ($a['down_payment'] ?? '0'),
            markupType: (string) ($a['markup_type'] ?? 'none'),
            markupValue: (string) ($a['markup_value'] ?? '0'),
            count: (int) ($a['count'] ?? 0),
            frequency: (string) ($a['frequency'] ?? ''),
            firstDueDate: (string) ($a['first_due_date'] ?? ''),
            customSchedule: $custom,
        );
    }
}
