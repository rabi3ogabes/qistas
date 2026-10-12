<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Paperwork\AddContractPhoto;
use App\Actions\Paperwork\CreateSignatureLink;
use App\Actions\Paperwork\RemoveContractPhoto;
use App\Actions\Paperwork\RemoveGuarantor;
use App\Actions\Paperwork\SaveGuarantor;
use App\Actions\Paperwork\SignContract;
use App\Http\Requests\ContractPhotoRequest;
use App\Http\Requests\GuarantorRequest;
use App\Models\Contract;
use App\Models\Guarantor;
use App\Models\StoredFile;
use App\Support\SignatureImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The paperwork on a contract's page (Win Plan K5): guarantors and photos (added, changed and removed) and the
 * customer's signature (on this screen, or a link and its code for their own phone). Each form comes back to the
 * contract with its own error bag, so the page shows a problem next to the form it belongs to.
 */
final class PaperworkController
{
    public function storeGuarantor(GuarantorRequest $request, Contract $contract, SaveGuarantor $save): RedirectResponse
    {
        Gate::authorize('addPaperwork', $contract);

        return $this->back($contract, 'guarantor', fn () => $save->add($contract, $request->guarantor(), $request->user()), __('Guarantor added.'));
    }

    public function updateGuarantor(GuarantorRequest $request, Guarantor $guarantor, SaveGuarantor $save): RedirectResponse
    {
        $contract = $guarantor->contract;
        Gate::authorize('addPaperwork', $contract);

        return $this->back($contract, 'guarantor-'.$guarantor->id, fn () => $save->update($guarantor, $request->guarantor(), $request->user()), __('Guarantor saved.'));
    }

    public function destroyGuarantor(Request $request, Guarantor $guarantor, RemoveGuarantor $remove): RedirectResponse
    {
        $contract = $guarantor->contract;
        Gate::authorize('removePaperwork', $contract);
        $remove->handle($guarantor, $request->user());

        return redirect()->route('app.contracts.show', $contract)->with('status', __('Guarantor removed.'));
    }

    public function storePhoto(ContractPhotoRequest $request, Contract $contract, AddContractPhoto $add): RedirectResponse
    {
        Gate::authorize('addPaperwork', $contract);

        return $this->back($contract, 'photo', fn () => $add->handle($contract, $request->kind(), $request->photo(), $request->user()), __('Photo kept with the contract.'));
    }

    public function destroyPhoto(Request $request, Contract $contract, StoredFile $photo, RemoveContractPhoto $remove): RedirectResponse
    {
        Gate::authorize('removePaperwork', $contract);
        $remove->handle($contract, $photo, $request->user());

        return redirect()->route('app.contracts.show', $contract)->with('status', __('Photo removed.'));
    }

    /** The customer signs on this screen, with the pad (Win Plan K5). */
    public function sign(Request $request, Contract $contract, SignContract $sign): RedirectResponse
    {
        Gate::authorize('addPaperwork', $contract);

        return $this->back($contract, 'signature', fn () => $sign->handle($contract, SignatureImage::fromDrawing((string) $request->input('drawing', '')), $request->user()), __('Signature kept with the contract.'));
    }

    /** A link for the customer to sign on their own phone, with its code: shown on the page once, never again. */
    public function signatureLink(Request $request, Contract $contract, CreateSignatureLink $create): RedirectResponse
    {
        Gate::authorize('addPaperwork', $contract);
        ['link' => $link, 'url' => $url, 'code' => $code] = $create->handle($contract, $request->user());

        return redirect()->route('app.contracts.show', $contract)
            ->with('signatureLink', ['url' => $url, 'code' => $code, 'expires_at' => $link->expires_at->toIso8601String()]);
    }

    /** Runs [$action] and returns to the contract, with a refusal shown next to its own form. */
    private function back(Contract $contract, string $bag, \Closure $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return redirect()->route('app.contracts.show', $contract)->withErrors($e->errors(), $bag)->withInput();
        }

        return redirect()->route('app.contracts.show', $contract)->with('status', $done);
    }
}
