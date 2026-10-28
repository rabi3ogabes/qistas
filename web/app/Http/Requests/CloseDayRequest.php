<?php

namespace App\Http\Requests;

use App\Models\DayClose;
use App\Models\Tenant;
use App\Support\WorkspaceDay;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing a day (daily-habit wave A, H4): the cash a person counted, a note if they have one, and the day when it is not
 * today. All it asks of the input is its shape: that the day is a date and the cash is text. Whether the day may be
 * closed, what the amount is worth and whether it is an amount at all are the close's own to say (App\Actions\CloseDay),
 * so the cash goes through exactly as it was typed and is never turned into a number on the way.
 */
final class CloseDayRequest extends FormRequest
{
    /** Anyone who records payments may close a day; asked before what was typed is looked at. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DayClose::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            'day' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function countedCash(): string
    {
        return (string) $this->validated('counted_cash');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }

    /** The day asked for on [$tenant]'s own calendar, or the workspace's today when none was. */
    public function day(Tenant $tenant): WorkspaceDay
    {
        $day = $this->validated('day');

        return is_string($day) ? WorkspaceDay::on($tenant, $day) : WorkspaceDay::of($tenant);
    }
}
