<?php

namespace App\Http\Requests;

use App\Documents\DocumentOptions;
use App\Documents\DocumentPreferences;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The choices for one statement, report or receipt (Win Plan PP8). Everything is optional: a document left alone follows
 * the workspace's preferences. A till roll is only for receipts.
 */
final class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(CurrentTenant::class)->id();
        $papers = $this->routeIs('*receipt*') ? DocumentOptions::RECEIPT_PAPERS : DocumentPreferences::PAPERS;

        return [
            'paper' => ['nullable', Rule::in($papers)],
            'text' => ['nullable', Rule::in(DocumentPreferences::TEXT_SIZES)],
            'sections' => ['nullable', 'array:'.implode(',', array_keys(DocumentPreferences::SECTIONS))],
            'sections.*' => ['boolean'],
            'summary' => ['nullable', 'boolean'],
            'compact' => ['nullable', 'boolean'],
            'language' => ['nullable', Rule::in((array) config('qistas.locales'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'contract' => ['nullable', 'uuid', Rule::exists('contracts', 'id')->where('tenant_id', $tenant)],
            'investor' => ['nullable', 'uuid', Rule::exists('investors', 'id')->where('tenant_id', $tenant)],
            'product' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenant)],
        ];
    }
}
