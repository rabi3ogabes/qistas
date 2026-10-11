<?php

use App\Models\ReleaseNote;
use App\Models\User;

/*
| Win Plan PP17: "What's new" after each update. Staff write a note per app version in each language and publish it; the
| app asks for the notes after the version it last showed, in the person's language (English where a version has no
| translation yet), newest first. Unpublished notes are never shown.
*/

function releaseNote(string $version, string $language, string $body, bool $published = true): void
{
    (new ReleaseNote)->forceFill(['version' => $version, 'language' => $language, 'body' => $body, 'published_at' => $published ? now() : null])->save();
}

function notesStaff(string $role = 'admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

it('gives the notes after a version, newest first, in the person’s language with English where needed', function () {
    releaseNote('1.14.0', 'en', 'Old news');
    releaseNote('1.15.0', 'en', 'Buy Pro in the app');
    releaseNote('1.15.0', 'ar', 'اشترِ برو من التطبيق');
    releaseNote('1.16.0', 'en', 'Help and a calculator');
    releaseNote('1.17.0', 'en', 'Not out yet', published: false);
    apiOwner();

    $this->getJson('/api/v1/release-notes?since=1.14.0', ['Accept-Language' => 'ar'])->assertOk()
        ->assertJsonPath('data', [
            ['version' => '1.16.0', 'language' => 'en', 'body' => 'Help and a calculator'],
            ['version' => '1.15.0', 'language' => 'ar', 'body' => 'اشترِ برو من التطبيق'],
        ]);
});

it('compares versions as versions, not as words', function () {
    releaseNote('1.9.0', 'en', 'Nine');
    releaseNote('1.10.0', 'en', 'Ten');
    apiOwner();

    $this->getJson('/api/v1/release-notes?since=1.9.0')->assertOk()->assertJsonPath('data.0.version', '1.10.0')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/release-notes?since=not-a-version')->assertStatus(422);
});

it('lets staff write a version’s notes in every language and publish them', function () {
    $this->actingAs(notesStaff())->post(route('admin.release-notes.store'), [
        'version' => '1.16.0',
        'body' => ['en' => 'Help inside the app.', 'ar' => 'المساعدة داخل التطبيق.', 'fr' => '', 'es' => '', 'ur' => ''],
        'publish' => '1',
    ])->assertRedirect(route('admin.release-notes.index'));

    expect(ReleaseNote::query()->where('version', '1.16.0')->pluck('body', 'language')->all())->toEqual(['en' => 'Help inside the app.', 'ar' => 'المساعدة داخل التطبيق.'])
        ->and(ReleaseNote::query()->whereNull('published_at')->count())->toBe(0);
    $this->actingAs(notesStaff())->get(route('admin.release-notes.index'))->assertOk()->assertSee('1.16.0');

    // Writing the same version again replaces its notes.
    $this->actingAs(notesStaff())->post(route('admin.release-notes.store'), ['version' => '1.16.0', 'body' => ['en' => 'Help and a calculator.']])->assertRedirect();
    expect(ReleaseNote::query()->where('version', '1.16.0')->pluck('body', 'language')->all())->toEqual(['en' => 'Help and a calculator.'])
        ->and(ReleaseNote::query()->where('version', '1.16.0')->whereNotNull('published_at')->count())->toBe(0);
});

it('needs a version number and an English note, and is for platform staff only', function () {
    [$owner] = owner();

    $this->actingAs(notesStaff())->post(route('admin.release-notes.store'), ['version' => 'soon', 'body' => ['en' => 'x']])->assertSessionHasErrors('version');
    $this->actingAs(notesStaff())->post(route('admin.release-notes.store'), ['version' => '1.16.0', 'body' => ['ar' => 'x']])->assertSessionHasErrors('body.en');
    $this->actingAs($owner)->get(route('admin.release-notes.index'))->assertNotFound();
});
