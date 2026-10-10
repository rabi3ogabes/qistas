<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\SendsDocuments;

/** Statements, reports and receipts as PDF files, for the app to share or save (Win Plan PP8). */
final class DocumentController
{
    use SendsDocuments;

    protected function disposition(): string
    {
        return 'attachment';
    }
}
