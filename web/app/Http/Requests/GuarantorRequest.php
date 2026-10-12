<?php

namespace App\Http\Requests;

use App\Models\Guarantor;
use App\Rules\PhoneNumber;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A guarantor as typed (Win Plan K5): a name, a phone, how they know the customer and an ID number. Arabic and Urdu
 * digits become plain ones. When changing one, a blank ID number keeps the one stored and `remove_national_id` clears
 * it. Errors go to their own bag on the web, so a page with several guarantor forms shows each its own.
 */
final class GuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'relation' => ['nullable', 'string', 'max:60'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'remove_national_id' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{name: string, phone: string, relation: ?string, national_id: ?string, remove_national_id: bool} */
    public function guarantor(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'phone' => (string) $this->validated('phone'),
            'relation' => $this->validated('relation'),
            'national_id' => $this->validated('national_id'),
            'remove_national_id' => $this->boolean('remove_national_id'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $guarantor = $this->route('guarantor');
        $this->errorBag = $guarantor instanceof Guarantor ? 'guarantor-'.$guarantor->id : 'guarantor';

        $text = fn (string $key): ?string => ($value = trim((string) $this->input($key))) === '' ? null : $value;

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => Digits::toAscii($text('phone') ?? ''),
            'relation' => $text('relation'),
            'national_id' => ($id = $text('national_id')) === null ? null : Digits::toAscii($id),
        ]);
    }
}
