<?php

namespace App\Http\Controllers;

use App\Site\SitePages;
use Illuminate\Http\Response;

/** The public pages for search engines: each at its plain address and in every language path, with its alternates. */
final class SitemapController
{
    public function __invoke(): Response
    {
        return response()
            ->view('site.sitemap', ['pages' => SitePages::LISTED, 'locales' => config('qistas.locales')])
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            // The same for everyone and changed only by a release: kept for an hour by browsers and the CDN.
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
