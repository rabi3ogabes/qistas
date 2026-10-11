<?php

/*
| Win Plan PL04: the web app can be installed from the browser (Add to home screen). A manifest names it, starts it at
| /app and gives it icons, including one safe to crop into any shape. A small service worker keeps only the app's own
| files (the built styles and scripts, the brand pictures) and an offline page; it never keeps a page or an answer with
| business data in it.
*/

it('has a manifest an installer accepts', function () {
    $response = $this->get('/manifest.webmanifest')->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    $manifest = $response->json();
    expect($manifest['name'])->toBe('Qistas')->and($manifest['short_name'])->toBe('Qistas')
        ->and($manifest['start_url'])->toBe('/app')->and($manifest['scope'])->toBe('/app')
        ->and($manifest['display'])->toBe('standalone')->and($manifest['id'])->toBe('/app')
        ->and($manifest['background_color'])->toMatch('/^#[0-9A-Fa-f]{6}$/')->and($manifest['theme_color'])->toMatch('/^#[0-9A-Fa-f]{6}$/');

    $sizes = collect($manifest['icons'])->groupBy('purpose')->map(fn ($icons) => $icons->pluck('sizes')->all());
    expect($sizes['any'])->toContain('192x192', '512x512')->and($sizes['maskable'])->toContain('512x512');
    foreach ($manifest['icons'] as $icon) {
        expect($icon['type'])->toBe('image/png')->and(public_path(ltrim($icon['src'], '/')))->toBeFile();
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('describes itself in the reader’s language', function () {
    expect($this->get('/manifest.webmanifest?lang=ar')->json('lang'))->toBe('ar')
        ->and($this->get('/manifest.webmanifest?lang=ar')->json('dir'))->toBe('rtl');
});

it('is linked from the web app, with its icon and service worker', function () {
    [$owner] = owner();

    $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()
        ->assertSee('<link rel="manifest" href="'.url('/manifest.webmanifest').'">', false)
        ->assertSee('apple-touch-icon', false);

    // The app's bundled script registers the worker on the web app's pages only (no inline script on the page).
    $script = (string) file_get_contents(resource_path('js/install.js'));
    expect($script)->toContain("register('/sw.js', { scope: '/app' })")->toContain("classList.contains('app-body')")
        ->and((string) file_get_contents(resource_path('js/app.js')))->toContain('registerServiceWorker();');
});

it('keeps only the app’s own files and an offline page, never a page or an answer with data in it', function () {
    $worker = (string) file_get_contents(public_path('sw.js'));

    // What it may keep: hashed build files and brand pictures, and the offline page.
    expect($worker)->toContain("const KEEP = ['/build/', '/brand/'];")
        ->and($worker)->toContain("'/offline.html'")
        // Pages are fetched from the network every time; when that fails, the offline page is shown instead.
        ->and($worker)->toContain("request.mode === 'navigate'")
        ->and($worker)->not->toContain('/api/');
    expect(public_path('offline.html'))->toBeFile();
});
