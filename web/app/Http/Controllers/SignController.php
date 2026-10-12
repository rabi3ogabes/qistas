<?php

namespace App\Http\Controllers;

use App\Actions\Paperwork\SignThroughLink;
use App\Documents\BusinessProfile;
use App\Http\ApiException;
use App\Models\Contract;
use App\Support\SignatureImage;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The page a signing link opens on the customer's own phone (Win Plan K5, decision D6). First the six-digit code; only
 * then the contract (what was sold, the total, the schedule) and the pad. Signing closes the link. A used, closed or
 * expired link, or one that never was, answers 410 with a plain explanation. Never indexed.
 */
final class SignController
{
    private const VERIFIED = 'sign.verified.';

    public function show(Request $request, string $token, CurrentTenant $current): Response
    {
        if ($request->session()->get('sign.done') === $token) {
            return response()->view('site.sign', ['state' => 'done', 'token' => $token]);
        }

        $link = SignThroughLink::find($token);
        if ($link === null || ! $link->isOpen()) {
            return $this->closed($token);
        }

        $business = BusinessProfile::for($link->tenant);
        if ($request->session()->get(self::VERIFIED.$link->id) !== true) {
            return response()->view('site.sign', ['state' => 'code', 'token' => $token, 'business' => $business->name(app()->getLocale())]);
        }

        // Inside the shop's workspace, so dates read the way the shop shows them (Hijri beside, when it does).
        return $current->use($link->tenant, fn (): Response => response()->view('site.sign', [
            'state' => 'sign',
            'token' => $token,
            'business' => $business->name(app()->getLocale()),
            'contract' => $link->contract->load(['customer', 'items', 'installments' => fn ($query) => $query->orderBy('number')]),
            'currency' => $link->tenant->currency,
        ]));
    }

    public function code(Request $request, string $token, SignThroughLink $links): RedirectResponse|Response
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);
        $link = SignThroughLink::find($token);
        if ($link === null || ! $link->isOpen()) {
            return $this->closed($token);
        }

        if (! $links->checkCode($link, (string) $request->input('code'))) {
            return redirect()->route('sign.show', ['token' => $token])
                ->withErrors(['code' => __('That code is not right. Check the text message from the shop and try again.')]);
        }

        $request->session()->put(self::VERIFIED.$link->id, true);

        return redirect()->route('sign.show', ['token' => $token]);
    }

    public function sign(Request $request, string $token, SignThroughLink $links): RedirectResponse|Response
    {
        $link = SignThroughLink::find($token);
        if ($link === null || ! $link->isOpen()) {
            return $this->closed($token);
        }
        abort_unless($request->session()->get(self::VERIFIED.$link->id) === true, 403);

        try {
            $links->sign($link, SignatureImage::fromDrawing((string) $request->input('drawing', '')), $request->ip(), $request->userAgent());
        } catch (ValidationException $e) {
            return redirect()->route('sign.show', ['token' => $token])->withErrors($e->errors());
        } catch (ApiException) {
            return $this->closed($token);
        }

        $request->session()->forget(self::VERIFIED.$link->id);

        return redirect()->route('sign.show', ['token' => $token])->with('sign.done', $token);
    }

    private function closed(string $token): Response
    {
        return response()->view('site.sign', ['state' => 'closed', 'token' => $token], 410);
    }
}
