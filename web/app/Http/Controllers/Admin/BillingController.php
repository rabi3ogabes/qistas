<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingSettings;
use App\Billing\ManualPayments;
use App\Models\ManualPaymentReceipt;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Billing in the admin area (Win Plan PP13): bank transfers waiting for a decision, with their receipts, and the
 * platform's billing settings. Any platform staff may look; a super admin decides and changes settings. Requests come
 * from every workspace, so they are read without the workspace scope here, and only here.
 */
final class BillingController
{
    public function index(BillingSettings $settings): View
    {
        $requests = ManualPaymentRequest::withoutGlobalScopes()->with(['tenant', 'plan', 'createdBy', 'decidedBy', 'receipt:id,manual_payment_request_id,mime,size'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest()->limit(50)->get();
        $account = $settings->playAccount();

        return view('admin.billing.index', [
            'pending' => $requests->where('status', 'pending')->values(),
            'decided' => $requests->where('status', '!=', 'pending')->values(),
            'settings' => [
                'auto_activate' => $settings->autoActivate(),
                'bank_details' => $settings->bankDetails(),
                'play_package' => $settings->playPackage(),
                'play_account_email' => is_string($account['client_email'] ?? null) ? $account['client_email'] : null,
                'play_products' => $settings->playProducts(),
                'push_audience' => $settings->pushAudience(),
                'push_email' => $settings->pushEmail(),
                'play_ready' => $settings->playReady(),
            ],
            'paidPlans' => Plan::query()->where('is_default', false)->orderBy('sort_order')->get(),
            'webhookUrl' => route('webhooks.google-play'),
            'canDecide' => Gate::allows('manage-plans'),
        ]);
    }

    /** The receipt itself, shown inline; never cached, and never guessed as another type. */
    public function receipt(string $paymentRequest): Response
    {
        $receipt = ManualPaymentReceipt::query()->where('manual_payment_request_id', $paymentRequest)->firstOrFail();
        Audit::record('billing.receipt_viewed', $receipt, [], ManualPaymentRequest::withoutGlobalScopes()->whereKey($paymentRequest)->value('tenant_id'));

        return response((string) base64_decode($receipt->data, true), 200, [
            'Content-Type' => $receipt->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function approve(Request $request, string $paymentRequest, ManualPayments $payments): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $approved = $payments->approve($this->find($paymentRequest), $request->user(), $data['note'] ?? null);

        return back()->with('status', __(':business is on :plan.', ['business' => $approved->tenant?->name, 'plan' => $approved->plan?->name]));
    }

    public function reject(Request $request, string $paymentRequest, ManualPayments $payments): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => __('Say why, so the owner knows what to do.')]);

        $payments->reject($this->find($paymentRequest), $request->user(), $data['note']);

        return back()->with('status', __('The request is rejected and the owner is told why.'));
    }

    public function settings(Request $request, BillingSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate([
            'auto_activate' => ['nullable', 'boolean'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'play_package' => ['nullable', 'string', 'max:150', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/'],
            'play_account' => ['nullable', 'string', 'max:10000'],
            'play_products' => ['nullable', 'array'],
            'play_products.*' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._]*$/'],
            'push_audience' => ['nullable', 'url:https', 'max:300'],
            'push_email' => ['nullable', 'email', 'max:200'],
        ]);

        // A new service account replaces the saved one; leaving the box empty keeps it.
        $account = trim((string) ($data['play_account'] ?? ''));
        if ($account !== '') {
            $decoded = json_decode($account, true);
            if (! is_array($decoded) || ! is_string($decoded['client_email'] ?? null) || ! is_string($decoded['private_key'] ?? null)) {
                throw ValidationException::withMessages(['play_account' => __('Paste the whole service account file Google gives (JSON with client_email and private_key).')]);
            }
            $settings->set(BillingSettings::PLAY_ACCOUNT, $account, $request->user());
        }

        $products = array_intersect_key(array_filter($data['play_products'] ?? [], fn ($id) => is_string($id) && $id !== ''), array_flip(Plan::query()->where('is_default', false)->pluck('key')->all()));
        $settings->set(BillingSettings::AUTO_ACTIVATE, ($data['auto_activate'] ?? false) ? '1' : '0', $request->user());
        $settings->set(BillingSettings::BANK_DETAILS, $data['bank_details'] ?? null, $request->user());
        $settings->set(BillingSettings::PLAY_PACKAGE, $data['play_package'] ?? null, $request->user());
        $settings->set(BillingSettings::PLAY_PRODUCTS, $products === [] ? null : (string) json_encode($products), $request->user());
        $settings->set(BillingSettings::PUSH_AUDIENCE, $data['push_audience'] ?? null, $request->user());
        $settings->set(BillingSettings::PUSH_EMAIL, $data['push_email'] ?? null, $request->user());
        Audit::record('admin.billing_settings_changed', null, [
            'auto_activate' => (bool) ($data['auto_activate'] ?? false), 'play_package' => $data['play_package'] ?? null,
            'play_account_replaced' => $account !== '', 'products' => $products,
        ], null, $request->user()->id);

        return back()->with('status', __('Billing settings saved.'));
    }

    private function find(string $id): ManualPaymentRequest
    {
        return ManualPaymentRequest::withoutGlobalScopes()->with(['tenant', 'plan'])->whereKey($id)->firstOrFail();
    }
}
