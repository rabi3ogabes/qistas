<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Schedule\ScheduleGenerator;
use App\Support\Digits;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The shape of a schedule in a request, shared by the contract form and the preview: every frequency, up to 600
 * instalments, and the shop's own rows of date and amount. Whether the workspace may use more than the basic plans is
 * decided when the contract is made (CreateContract), not here.
 */
trait ScheduleRules
{
    protected function frequencyRule(): In
    {
        return Rule::in([...array_keys(ScheduleGenerator::FREQUENCIES), ScheduleGenerator::CUSTOM]);
    }

    /**
     * Rules for the shop's own rows; the generator then checks the order of the dates and that they add up.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function customScheduleRules(bool $required): array
    {
        return [
            'custom_schedule' => [Rule::requiredIf($required), 'nullable', 'array', 'min:1', 'max:'.ScheduleGenerator::MAX_COUNT],
            'custom_schedule.*.due_date' => ['required', 'date_format:Y-m-d'],
            'custom_schedule.*.amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * Each row's problem in plain words, with its row number (the validator fills in :position, counting from 1).
     *
     * @return array<string, string>
     */
    protected function customScheduleMessages(): array
    {
        return [
            'custom_schedule.required' => __('Add at least one payment date.'),
            'custom_schedule.min' => __('Add at least one payment date.'),
            'custom_schedule.max' => __('A plan can have up to 600 payments.'),
            'custom_schedule.*.due_date.required' => __('Row :position: enter the date.'),
            'custom_schedule.*.due_date.date_format' => __('Row :position: enter a real date.'),
            'custom_schedule.*.amount.required' => __('Row :position: enter the amount.'),
            'custom_schedule.*.amount.string' => __('Row :position: enter the amount as a number, like 250.50.'),
            'custom_schedule.*.amount.regex' => __('Row :position: enter the amount as a number, like 250.50.'),
        ];
    }

    /**
     * The rows as typed, with Arabic-Indic digits made plain and blank rows dropped; null when none were sent.
     *
     * @return list<array{due_date: string, amount: string}>|null
     */
    protected function cleanCustomSchedule(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $rows = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = is_scalar($row['due_date'] ?? null) ? Digits::toAscii(trim((string) $row['due_date'])) : '';
            $amount = is_scalar($row['amount'] ?? null) ? Digits::toAscii(trim((string) $row['amount'])) : '';
            if ($date === '' && $amount === '') {
                continue;
            }
            $rows[] = ['due_date' => $date, 'amount' => $amount];
        }

        return $rows;
    }
}
