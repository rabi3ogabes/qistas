<?php

namespace App\Http\Requests;

use App\Documents\DocumentPreferences;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** How the workspace's documents look by default. Only the owner and managers change it. */
final class DocumentPreferencesRequest extends FormRequest
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
            'paper' => ['nullable', Rule::in(DocumentPreferences::PAPERS)],
            'text' => ['nullable', Rule::in(DocumentPreferences::TEXT_SIZES)],
            'sections' => ['nullable', 'array:'.implode(',', array_keys(DocumentPreferences::SECTIONS))],
            'sections.*' => ['boolean'],
            'wording' => ['nullable', 'array:'.implode(',', array_keys(DocumentPreferences::WORDS))],
            'wording.*' => ['nullable', 'string', 'max:'.DocumentPreferences::WORD_LENGTH],
        ];
    }

    /**
     * Booleans as booleans, whatever form they came in (a checkbox sends "1", JSON sends true).
     *
     * @return array<string, mixed>
     */
    public function preferences(): array
    {
        $validated = $this->validated();
        if (isset($validated['sections'])) {
            $validated['sections'] = array_map(fn (mixed $on) => filter_var($on, FILTER_VALIDATE_BOOL), $validated['sections']);
        }

        return $validated;
    }
}
