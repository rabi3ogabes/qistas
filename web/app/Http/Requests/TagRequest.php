<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A tag's name (unique in the business whatever its case) and colour. Anyone who may write. */
final class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId !== null && ($this->user()?->roleIn($tenantId)?->canWrite() ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', $this->unique()],
            'colour' => ['nullable', Rule::in(Tag::COLOURS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name')))]);
    }

    private function unique(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $tag = $this->route('tag');
            $taken = DB::table('tags')->where('tenant_id', app(CurrentTenant::class)->id())
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                ->when($tag instanceof Tag, fn ($query) => $query->where('id', '!=', $tag->id))
                ->exists();
            if ($taken) {
                $fail(__('There is already a tag called :name.', ['name' => $value]));
            }
        };
    }
}
