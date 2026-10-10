<?php

namespace App\Http\Controllers\Concerns;

use App\Documents\DocumentOptions;
use App\Documents\DocumentService;
use App\Documents\Template;
use App\Documents\Templates\ContractStatement;
use App\Documents\Templates\CustomerStatement;
use App\Documents\Templates\InvestorReport;
use App\Documents\Templates\Receipt;
use App\Documents\Templates\TransactionsReport;
use App\Http\Requests\DocumentRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Investor;
use App\Models\Transaction;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The statements, reports and receipts (Win Plan PP8), the same for the web app and the API: who may have each, and the
 * PDF it answers with. The web opens it in the browser; the API hands over the file.
 */
trait SendsDocuments
{
    public function customerStatement(DocumentRequest $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        return $this->sendDocument(new CustomerStatement($customer), $request);
    }

    public function contractStatement(DocumentRequest $request, Contract $contract): Response
    {
        Gate::authorize('view', $contract);

        return $this->sendDocument(new ContractStatement($contract), $request);
    }

    /** Only money that came in has a receipt; a void is not one. */
    public function receipt(DocumentRequest $request, Transaction $transaction): Response
    {
        abort_unless(in_array($transaction->type, ['payment', 'down_payment'], true), 404);
        Gate::authorize('view', $transaction);

        return $this->sendDocument(new Receipt($transaction), $request);
    }

    /** The money that came in over a period: for everyone who sees the business's money, not a collector. */
    public function transactions(DocumentRequest $request): Response
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant !== null && ($request->user()?->roleIn($tenant->id)?->seesInvestors() ?? false), 403);

        return $this->sendDocument(new TransactionsReport, $request);
    }

    public function investorReport(DocumentRequest $request, Investor $investor): Response
    {
        Gate::authorize('view', $investor);

        return $this->sendDocument(new InvestorReport($investor), $request);
    }

    private function sendDocument(Template $template, DocumentRequest $request): Response
    {
        $tenant = app(CurrentTenant::class)->get() ?? abort(404);
        $issued = app(DocumentService::class)->issue($template, DocumentOptions::from($request->validated(), $tenant, $request->user()), $tenant, $request->user());

        return response($issued['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->disposition().'; filename="'.$issued['filename'].'"',
            'X-Document-Code' => $issued['document']->verification_code,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** inline (the browser shows it) or attachment (the file is handed over). */
    abstract protected function disposition(): string;
}
