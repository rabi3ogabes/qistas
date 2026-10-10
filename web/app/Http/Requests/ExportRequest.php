<?php

namespace App\Http\Requests;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Taking the whole of the books out: Excel (one file) or CSV (a zip). Owners, managers and accountants, on any plan. */
final class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId !== null && ($this->user()?->roleIn($tenantId)?->canExport() ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['format' => ['nullable', Rule::in(['xlsx', 'csv'])]];
    }

    public function exportFormat(): string
    {
        return (string) ($this->validated('format') ?? 'xlsx');
    }
}
