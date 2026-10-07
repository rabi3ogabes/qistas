<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** The public pages, each with its language alternates, for search engines. */
final class SitemapController
{
    private const PAGES = ['/', '/pricing', '/terms', '/privacy'];

    public function __invoke(): Response
    {
        return response()
            ->view('site.sitemap', ['pages' => self::PAGES, 'locales' => config('qistas.locales')])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
