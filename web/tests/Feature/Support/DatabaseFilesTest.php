<?php

use App\Models\StoredFile;
use App\Support\FileKind;
use App\Support\Files;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Files kept in the database (Win Plan D5). A host with no lasting disk (Vercel throws its disk away) keeps the private
| `files` disk in the `file_blobs` table instead. Files works exactly as before: the same paths, the same checks, the
| same five-minute links, served by a signed route of our own. Moving to a bucket later is a change of disk.
*/

beforeEach(function () {
    useDatabaseFiles();
    [$this->user, $this->tenant] = owner();
    $this->customer = customerIn($this->tenant);
});

function keepInDatabase(string $bytes, FileKind $kind = FileKind::PaymentProof): StoredFile
{
    return asTenant(test()->tenant, fn () => app(Files::class)->put($bytes, $kind, test()->customer, test()->user));
}

it('keeps the cleaned bytes in the database under the file’s own path', function () {
    $file = keepInDatabase(jpegBytes(withExif: true), FileKind::IdFront);
    $row = DB::table('file_blobs')->where('path', $file->path)->first();

    expect($row)->not->toBeNull()
        ->and($file->path)->toStartWith('tenants/'.$this->tenant->id.'/id_front/')
        ->and(base64_decode($row->contents))->not->toContain('GPS:')
        ->and((int) $row->size)->toBe($file->size)
        ->and($row->mime)->toBe('image/jpeg')
        ->and(Storage::disk(Files::DISK)->get($file->path))->toBe(base64_decode($row->contents));
});

it('serves a file through its signed link for five minutes, as what it is, and refuses it after', function () {
    $this->travelTo('2026-10-12 12:00:00');
    $file = keepInDatabase(pdfBytes(), FileKind::Statement);
    $url = app(Files::class)->temporaryUrl($file, 3600);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe(now()->addSeconds(300)->getTimestamp());
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toBe(pdfBytes())
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');

    $this->travelTo(now()->addMinutes(6));
    $this->get($url)->assertForbidden();
});

it('refuses a link that was tampered with or turned towards another file', function () {
    $file = keepInDatabase(pdfBytes(), FileKind::Statement);
    $url = app(Files::class)->temporaryUrl($file);

    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    $this->get(str_replace($file->id, (string) Str::uuid(), $url))->assertForbidden();
});

it('takes the bytes away when the file is removed, and keeps the record marked deleted', function () {
    $file = keepInDatabase(jpegBytes());

    app(Files::class)->delete($file);

    expect(DB::table('file_blobs')->where('path', $file->path)->exists())->toBeFalse()
        ->and(asTenant($this->tenant, fn () => StoredFile::withTrashed()->find($file->id)?->trashed()))->toBeTrue();
});

it('keeps a picture to 1600 pixels on its longest side, so a phone photo stays a few hundred KB', function () {
    $file = keepInDatabase(hugePng(2400));
    $size = getimagesizefromstring((string) Storage::disk(Files::DISK)->get($file->path));

    expect([$size[0], $size[1]])->toBe([1600, 1600]);
});
