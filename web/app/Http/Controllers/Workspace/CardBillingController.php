<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingState;
use App\Billing\Card\CardManagement;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\StripePrices;
use App\Http\Requests\CardCancelRequest;
use App\Models\Tenant;
use App\Support\Dates;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The owner managing a card subscription on the billing page (spec 2026-10-27 §4.3). Plain forms answer with the page
 * and a sentence; the two steps that run Stripe.js (a new card, a payment the bank holds) answer in JSON.
 */
final class CardBillingController
{
    public function __construct(private readonly CardManagement $cards) {}

    public function setup(Request $request, CurrentTenant $tenants): JsonResponse
    {
        $tenant = $this->owned($request, $tenants);

        return $this->json(fn () => $this->cards->startCardChange($tenant));
    }

    public function setupReturn(Request $request, CurrentTenant $tenants): RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);

        return $this->page(function () use ($request, $tenant): string {
            try {
                $saved = $this->cards->finishCardChange($tenant, (string) $request->query('session_id', ''), $request->user());
            } catch (ModelNotFoundException|CardRefused $e) {
                abort(404);
            }

            return $saved ? __('Your new card is saved.') : __('Your card was not changed.');
        });
    }

    public function previewSwitch(Request $request, CurrentTenant $tenants): JsonResponse
    {
        $tenant = $this->owned($request, $tenants);
        $period = $request->validate(['period' => ['required', Rule::in(StripePrices::PERIODS)]])['period'];

        return $this->json(fn () => $this->cards->previewSwitch($tenant, $period));
    }

    public function switch(Request $request, CurrentTenant $tenants): RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);
        $period = $request->validate(['period' => ['required', Rule::in(StripePrices::PERIODS)]])['period'];

        return $this->page(function () use ($tenant, $period, $request): string {
            $when = $this->cards->switchPeriod($tenant, $period, $request->user());
            $until = Dates::day($tenant->subscription()->first()?->current_period_end);

            return match ($when) {
                'now' => __('Pro now renews every year.'),
                'period_end' => __('Pro renews monthly from :date.', ['date' => $until]),
                default => __('Pro keeps renewing as it does now.'),
            };
        });
    }

    public function cancel(CardCancelRequest $request, CurrentTenant $tenants): RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);

        return $this->page(function () use ($tenant, $request): string {
            $subscription = $this->cards->cancel($tenant, $request->validated('reason'), $request->validated('details'), $request->user());

            return __('Pro stays until :date. You won\'t be charged again.', ['date' => Dates::day($subscription->current_period_end)]);
        });
    }

    public function resume(Request $request, CurrentTenant $tenants): RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);

        return $this->page(function () use ($tenant, $request): string {
            $subscription = $this->cards->resume($tenant, $request->user());

            return __('Pro renews again on :date.', ['date' => Dates::day($subscription->current_period_end)]);
        });
    }

    public function confirm(Request $request, CurrentTenant $tenants): JsonResponse
    {
        $tenant = $this->owned($request, $tenants);

        return $this->json(fn () => $this->cards->paymentToConfirm($tenant) ?? throw new CardRefused(__('There is no payment to confirm.')));
    }

    public function receipt(Request $request, CurrentTenant $tenants, string $invoice): Response
    {
        $tenant = $this->owned($request, $tenants);

        try {
            $receipt = $this->cards->receiptPdf($tenant, $invoice);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (CardUnavailable) {
            abort(503, __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.'));
        }

        return response($receipt['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt['name'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function owned(Request $request, CurrentTenant $tenants): Tenant
    {
        $tenant = $tenants->get();
        abort_if($tenant === null || ! BillingState::canBuy($tenant, $request->user()), 403);

        return $tenant;
    }

    /** @param  Closure(): string  $action  returns the sentence to show on the billing page */
    private function page(Closure $action): RedirectResponse
    {
        try {
            return redirect()->route('app.billing')->with('status', $action());
        } catch (CardRefused $e) {
            return redirect()->route('app.billing')->withErrors(['card' => $e->getMessage()]);
        } catch (CardUnavailable) {
            return redirect()->route('app.billing')->withErrors(['card' => __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.')]);
        }
    }

    /** @param  Closure(): array<string, mixed>  $action */
    private function json(Closure $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (CardRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CardUnavailable) {
            return response()->json(['message' => __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.')], 503);
        }
    }
}
