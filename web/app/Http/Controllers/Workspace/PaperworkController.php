<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Paperwork\AddContractPhoto;
use App\Actions\Paperwork\RemoveContractPhoto;
use App\Actions\Paperwork\RemoveGuarantor;
use App\Actions\Paperwork\SaveGuarantor;
use App\Http\Requests\ContractPhotoRequest;
use App\Http\Requests\GuarantorRequest;
use App\Models\Contract;
use App\Models\Guarantor;
use App\Models\StoredFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The paperwork on a contract's page (Win Plan K5): guarantors and photos, added, changed and removed. Each form
 * comes back to the contract with its own error bag, so the page shows a problem next to the form it belongs to.
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
