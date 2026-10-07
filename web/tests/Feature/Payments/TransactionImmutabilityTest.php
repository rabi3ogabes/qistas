<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = workspaceOn();
    $this->contract = openContract($this->tenant);
    $this->transaction = app(RecordPayment::class)->handle($this->contract, '150.00', 'cash');
});

it('cannot be edited once recorded', function () {
    $stored = asTenant($this->tenant, fn () => Transaction::find($this->transaction->id));

    expect(fn () => asTenant($this->tenant, fn () => $stored->forceFill(['amount' => '1.00'])->save()))->toThrow(LogicException::class);
});

it('cannot be deleted', function () {
    $stored = asTenant($this->tenant, fn () => Transaction::find($this->transaction->id));

    expect(fn () => asTenant($this->tenant, fn () => $stored->delete()))->toThrow(LogicException::class);
    expect(asTenant($this->tenant, fn () => Transaction::count()))->toBe(1);
});

it('keeps its allocations just as fixed', function () {
    $allocation = asTenant($this->tenant, fn () => TransactionAllocation::where('transaction_id', $this->transaction->id)->first());

    expect(fn () => asTenant($this->tenant, fn () => $allocation->forceFill(['amount' => '1.00'])->save()))->toThrow(LogicException::class)
        ->and(fn () => asTenant($this->tenant, fn () => $allocation->delete()))->toThrow(LogicException::class);
});

it('cannot be mass-assigned from a request', function () {
    expect(fn () => asTenant($this->tenant, fn () => Transaction::create(['amount' => '999.00', 'type' => 'payment'])))
        ->toThrow(MassAssignmentException::class);
});

it('has a reversal recorded at most once per payment', function () {
    $reversal = app(VoidTransaction::class)->handle($this->transaction);

    // Inside its own savepoint: on PostgreSQL a failed statement poisons the whole surrounding transaction.
    expect(fn () => DB::transaction(fn () => asTenant($this->tenant, fn () => (new Transaction)->forceFill([
        'contract_id' => $this->contract->id, 'customer_id' => $this->contract->customer_id, 'type' => 'reversal', 'method' => 'cash',
        'amount' => '-150.00', 'paid_at' => now(), 'reverses_transaction_id' => $this->transaction->id,
    ])->save())))->toThrow(QueryException::class);
    expect($reversal->reverses_transaction_id)->toBe($this->transaction->id);
})->group('db-errors'); // makes the database raise an error: left out when running against the PGlite stand-in
