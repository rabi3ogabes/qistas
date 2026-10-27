<?php

namespace App\Http\Requests;

use App\Billing\Card\CardManagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Why the owner turns renewal off: optional, a reason from the list and a few words, kept in the activity log only. */
final class CardCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', Rule::in(CardManagement::CANCEL_REASONS)],
            'details' => ['nullable', 'string', 'max:500'],
        ];
    }
}
