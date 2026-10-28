<?php

namespace App\Http\Resources;

use App\Domain\Ledger\ContractAccount;
use App\Http\Resources\Concerns\Presents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractAccount
 *
 * One contract as the customer's screen shows it: its lines in date order, each with what it `added`, what was `paid`
 * and the `balance` after it; a line of kind `unpaid` is an instalment past its date and still owed (no balance). The
 * totals: `instalment`, `paid_count` and `left_count` (null for an open contract), `paid`, `left` and `late`.
 */
class ContractAccountResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractAccount $account */
        $account = $this->resource;

        return [
            'contract_id' => $account->contract->id,
            'open' => $account->contract->isOpen(),
            'takes_payments' => $account->takesPayments(),
            'next_installment_id' => $account->next?->id,
            'lines' => array_map(fn (array $row) => [
                'kind' => $row['kind'],
                'date' => $this->day($row['date']),
                'added' => $this->money($row['debit']),
                'paid' => $this->money($row['credit']),
                'balance' => $this->money($row['balance']),
                'transaction_id' => $row['transaction']?->id,
                'method' => $row['transaction']?->method,
                'tag' => $row['transaction']?->tag,
                'note' => $row['transaction']?->note,
                'installment' => $row['unpaid'] === null ? null : [
                    'id' => $row['unpaid']->id,
                    'number' => $row['unpaid']->number,
                    'due_date' => $this->day($row['unpaid']->due_date),
                    'remaining' => $this->money($row['unpaid']->remaining()),
                ],
            ], $account->rows),
            'totals' => [
                'instalment' => $this->money($account->instalment),
                'paid_count' => $account->paidCount,
                'left_count' => $account->leftCount,
                'paid' => $this->money($account->paid),
                'left' => $this->money($account->left),
                'late' => $this->money($account->late),
            ],
        ];
    }
}
