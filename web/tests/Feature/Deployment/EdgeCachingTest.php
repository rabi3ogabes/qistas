<?php

/*
| Files that are the same for every visitor carry no sign-in cookie and say they may be kept, so the network's edge
| near the visitor (Vercel's CDN) answers them instead of the server across the world. Pages that differ per person
| keep their session.
*/

it('keeps robots.txt and the sitemap free of cookies and cacheable', function (string $path) {
    $response = $this->get($path)->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=');
})->with(['/robots.txt', '/sitemap.xml']);

it('still starts a session on pages, where a form needs one', function () {
    expect($this->get('/login')->assertOk()->headers->getCookies())->not->toBe([]);
});
