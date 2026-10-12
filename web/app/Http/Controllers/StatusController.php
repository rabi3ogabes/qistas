<?php

namespace App\Http\Controllers;

use App\Site\ServiceStatus;
use Illuminate\Http\Response;

/** The public status page (Sprint 2, D10): checked on every visit, never kept by a cache. */
final class StatusController
{
    public function __invoke(): Response
    {
        return response()->view('site.status', ['status' => ServiceStatus::check(), 'checked' => now()])
            ->header('Cache-Control', 'no-store');
    }
}
