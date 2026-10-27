<?php

namespace App\Http\Requests;

use App\Billing\Card\StripePrices;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which offer a card checkout is opened for: a plan and a period, never an amount, and the page's attempt token. */
final class CardCheckoutRequest extends FormRequest
{
    /** Who may buy is decided with the workspace (owner only) in the controller, so a refusal reads the same everywhere. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'max:40'],
            'period' => ['required', Rule::in(StripePrices::PERIODS)],
            'attempt' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{16,100}$/'],
        ];
    }
}
