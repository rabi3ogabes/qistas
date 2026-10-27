<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Card\CardConnection;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

/**
 * Card payments (Stripe) in /admin/billing (spec 2026-10-27 §8): the two keys, saved and connected in one step;
 * Reconnect; Disconnect; and the checkout's options. A super admin only ('manage-plans'); the panel itself is drawn by
 * BillingController::index for all platform staff.
 */
final class CardSettingsController
{
    public function keys(Request $request, CardConnection $connection, CardSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate([
            'secret_key' => ['required', 'string', 'max:255'],
            'publishable_key' => ['required', 'string', 'max:255'],
        ], [
            'secret_key.required' => __('Paste the secret key from Stripe (Developers, API keys).'),
            'publishable_key.required' => __('Paste the publishable key from Stripe (Developers, API keys).'),
        ]);

        try {
            $connection->replaceKeys($data['secret_key'], $data['publishable_key'], $request->user());
        } catch (CardRefused|CardUnavailable $e) {
            throw ValidationException::withMessages(['secret_key' => self::refusal($e)]);
        }

        return $this->connected($settings);
    }

    public function connect(Request $request, CardConnection $connection, CardSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');

        try {
            $connection->connect($request->user());
        } catch (CardRefused|CardUnavailable $e) {
            throw ValidationException::withMessages(['stripe' => self::refusal($e)]);
        }

        return $this->connected($settings);
    }

    public function disconnect(Request $request, CardConnection $connection): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $connection->disconnect($request->user());

        return redirect()->route('admin.billing.index')->with('status', __('Stripe is disconnected. Card payments are off until you connect again.'));
    }

    public function settings(Request $request, CardSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $trial = __('The free trial is between 0 and :max days.', ['max' => CardSettings::MAX_TRIAL_DAYS]);
        $data = $request->validate([
            'trial_days' => ['required', 'integer', 'min:0', 'max:'.CardSettings::MAX_TRIAL_DAYS],
            'promotion_codes' => ['nullable', 'boolean'],
            'collect_tax_id' => ['nullable', 'boolean'],
            'automatic_tax' => ['nullable', 'boolean'],
        ], ['trial_days.required' => $trial, 'trial_days.integer' => $trial, 'trial_days.min' => $trial, 'trial_days.max' => $trial]);

        $settings->saveOptions([
            'trial_days' => (int) $data['trial_days'],
            'promotion_codes' => (bool) ($data['promotion_codes'] ?? false),
            'collect_tax_id' => (bool) ($data['collect_tax_id'] ?? false),
            'automatic_tax' => (bool) ($data['automatic_tax'] ?? false),
        ], $request->user());

        return redirect()->route('admin.billing.index')->with('status', __('Card payment options saved.'));
    }

    private function connected(CardSettings $settings): RedirectResponse
    {
        return redirect()->route('admin.billing.index')->with('status', $settings->mode() === 'live'
            ? __('Stripe is connected. Card payments are ready in live mode.')
            : __('Stripe is connected. Card payments are ready in test mode.'));
    }

    /** Stripe's own words when it gave any (a key it refuses, a URL it will not call); ours otherwise. */
    private static function refusal(CardRefused|CardUnavailable $e): string
    {
        $stripe = $e->getPrevious();
        if ($stripe instanceof ApiErrorException && $stripe->getMessage() !== '') {
            return __('Stripe says: :message', ['message' => $stripe->getMessage()]);
        }

        return $e instanceof CardUnavailable ? __('Stripe could not be reached. Nothing was changed; try again in a minute.') : $e->getMessage();
    }
}
