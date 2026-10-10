<?php

namespace App\Http\Requests;

use App\Activity\ActivityFeed;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Reading the activity log: owners and managers, filtered by person, kind and day. */
final class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId !== null && ($this->user()?->roleIn($tenantId)?->canManageSettings() ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(array_keys(ActivityFeed::KINDS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array{user?: string|null, kind?: string|null, from?: string|null, to?: string|null} */
    public function filters(): array
    {
        /** @var array{user?: string|null, kind?: string|null, from?: string|null, to?: string|null} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
