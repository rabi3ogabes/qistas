<?php

namespace App\Http\Requests;

/** Voiding is for owners and managers; the policy decides, with the payment the route resolved. */
class VoidPaymentRequest extends ReasonRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('transaction')) ?? false;
    }
}
