<?php

namespace App\Actions;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Removes a customer from the lists (a soft delete: contracts and payments stay in the books). A customer who
 * still has a running contract cannot be removed from under it.
 */
final class DeleteCustomer
{
    /** @throws ValidationException */
    public function handle(Customer $customer): void
    {
        if (Contract::query()->where('customer_id', $customer->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'customer' => __('This customer has a running contract. Settle or cancel it first.'),
            ]);
        }

        $customer->delete();
    }
}
