<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingState;
use App\Billing\ManualPayments;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owner reports a bank transfer for a plan, with its receipt (Win Plan PP13). Website only: the app never offers
 * this, as Google Play's payments policy requires. ManualPayments decides.
 */
final class ManualPaymentController
{
    public function store(Request $request, CurrentTenant $current, ManualPayments $payments): RedirectResponse
    {
        $tenant = $current->get() ?? abort(403);
        abort_unless(BillingState::canBuy($tenant, $request->user()), 403);
        Entitlements::for($tenant)->assertEnabled(Feature::ManualBilling);

        // The form sends the plan and the period as one choice ("pro:year"); the parts may also come on their own.
        if ($request->filled('offer')) {
            [$plan, $period] = explode(':', (string) $request->input('offer'), 2) + [1 => ''];
            $request->merge(['plan' => $plan, 'period' => $period]);
        }
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'period' => ['required', 'string'],
            'method' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100'],
            'receipt' => ['required', 'file'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ], [
            'plan.required' => __('Choose what you paid for.'),
            'period.required' => __('Choose what you paid for.'),
            'reference.required' => __('Write the transfer’s reference, as your bank shows it.'),
            'receipt.required' => __('Add the receipt: a photo, a screenshot or the PDF from your bank.'),
        ]);

        $sent = $payments->submit($tenant, $data['plan'], $data['period'], $data['method'], trim($data['reference']), $request->file('receipt'), $request->user(), $data['idempotency_key'] ?? null);

        return redirect()->route('app.billing')->with('status', $sent->status === 'approved'
            ? __('Thank you. Your plan is on.')
            : __('Thank you. The Qistas team checks the transfer and turns your plan on, usually within one working day.'));
    }
}
