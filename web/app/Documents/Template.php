<?php

namespace App\Documents;

use Illuminate\Database\Eloquent\Model;

/**
 * One kind of document (Win Plan PP8): what it is about, what it says and how its file is named. Built for the active
 * workspace; DocumentService renders it, counts it against the plan's allowance and records it for /verify.
 */
interface Template
{
    /** customer_statement | contract_statement | transactions_report | investor_report | receipt */
    public function kind(): string;

    /** The record the document is about, if it is about one. */
    public function subject(): ?Model;

    public function view(): string;

    /**
     * What the view shows. Called once per document, before reference() and total().
     *
     * @return array<string, mixed>
     */
    public function data(DocumentOptions $options): array;

    /** What the verification page calls it: a contract or receipt number, never a person's name. */
    public function reference(): string;

    /** The amount the verification page shows, if the document has one. */
    public function total(): ?string;

    public function filename(DocumentOptions $options): string;
}
