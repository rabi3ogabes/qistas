<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

final readonly class ScheduleRequest
{
    public function __construct(
        public string $principal,
        public string $downPayment,
        public string $markupType,   // none | fixed | percent
        public string $markupValue,
        public int $count,
        public string $frequency,    // weekly | biweekly | monthly
        public string $firstDueDate, // Y-m-d
    ) {}

    /** @param array<string,mixed> $a snake_case input as used by the API and the shared vectors */
    public static function fromArray(array $a): self
    {
        return new self(
            principal: (string) ($a['principal'] ?? ''),
            downPayment: (string) ($a['down_payment'] ?? '0'),
            markupType: (string) ($a['markup_type'] ?? 'none'),
            markupValue: (string) ($a['markup_value'] ?? '0'),
            count: (int) ($a['count'] ?? 0),
            frequency: (string) ($a['frequency'] ?? ''),
            firstDueDate: (string) ($a['first_due_date'] ?? ''),
        );
    }
}
