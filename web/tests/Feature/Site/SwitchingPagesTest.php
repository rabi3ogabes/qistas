<?php

use App\Entitlements\Feature;
use App\Import\Ai\AiSettings;

/*
| Pages for a shop moving to Qistas from a notebook, from an Excel sheet or from another app (Sprint 2, decision D10).
| Each says how the move goes, and promises only what is switched on for everyone right now.
*/

it('tells a shop how moving goes, from a notebook, from Excel or from another app', function (string $path, string $title) {
    $this->get($path)->assertOk()
        ->assertSee($title)
        ->assertSee('How the move goes')
        ->assertSee(route('register'), false)
        ->assertSee('Record payments as they come');
})->with([
    ['/switch/notebook', 'Move your notebook to Qistas'],
    ['/switch/excel', 'From your Excel sheet to Qistas'],
    ['/switch/another-app', 'Bring your books from another app'],
]);

it('reads in each language', function () {
    $this->get('/ar/switch/excel')->assertOk()->assertSee('من ملف Excel إلى قسطاس');
    $this->get('/fr/switch/notebook')->assertOk()->assertSee('Passez de votre cahier à Qistas');
});

it('promises bringing a file in only while importing is switched on for everyone', function () {
    $this->get('/switch/excel')->assertDontSee('Bring your sheet in')->assertSee('Add your customers');

    switchOn(Feature::Import);

    $this->get('/switch/excel')->assertSee('Bring your sheet in')->assertSee('Check the trial run, then confirm')->assertDontSee('Add your customers');
    $this->get('/switch/another-app')->assertSee('Export from your old app');
    $this->get('/switch/notebook')->assertSee('Copy the list into the template');
});

it('mentions photos of the notebook and PDF reports only once they are read', function () {
    switchOn(Feature::Import);
    switchOn(Feature::AiReading);

    $this->get('/switch/notebook')->assertDontSee('Photograph a page');
    $this->get('/switch/another-app')->assertDontSee('PDF reports');

    app(AiSettings::class)->setKey('sk-ant-test-key', null);

    $this->get('/switch/notebook')->assertSee('Photograph a page')->assertDontSee('Copy the list into the template');
    $this->get('/switch/another-app')->assertSee('PDF reports');
});

it('is found from the footer of every public page', function () {
    $html = $this->get('/pricing')->getContent();

    expect($html)->toContain('href="'.url('/switch/notebook').'"')
        ->toContain('href="'.url('/switch/excel').'"')
        ->toContain('href="'.url('/switch/another-app').'"');
});
