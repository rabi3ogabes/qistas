<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Concerns\SendsDocuments;

/** Statements, reports and receipts as PDF files, opened in the browser to print, save or share (Win Plan PP8). */
final class DocumentController
{
    use SendsDocuments;

    protected function disposition(): string
    {
        return 'inline';
    }
}
