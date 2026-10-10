<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Putting a business on a plan from the admin area: which plan, for how long (no end, one, three or twelve months, or
 * until a chosen day) and why. Only a super admin.
 */
final class ChangePlanRequest extends FormRequest
{
    /** How long a plan can be given for, in months; or no end, or until a chosen day. */
    public const PERIODS = ['none', '1', '3', '12', 'date'];

    public function authorize(): bool
    {
        return Gate::allows('manage-plans');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::exists('plans', 'key')],
            'period' => ['required', Rule::in(self::PERIODS)],
            'until' => ['nullable', 'required_if:period,date', 'date_format:Y-m-d', 'after:today'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['plan' => __('plan'), 'period' => __('how long'), 'until' => __('last day'), 'reason' => __('reason')];
    }

    /** The last day the plan is given, from today for a number of months; null for no end. */
    public function until(): ?CarbonImmutable
    {
        $period = (string) $this->validated('period');

        return match ($period) {
            'none' => null,
            'date' => CarbonImmutable::parse((string) $this->validated('until')),
            default => CarbonImmutable::today()->addMonthsNoOverflow((int) $period),
        };
    }
}
