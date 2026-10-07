<?php

namespace App\Http\Requests;

/** Cancelling is for owners and managers; the policy decides, with the contract the route resolved. */
class CancelContractRequest extends ReasonRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancel', $this->route('contract')) ?? false;
    }
}
