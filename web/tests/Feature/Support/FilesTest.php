<?php

use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Support\FileKind;
use App\Support\Files;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Private file storage: what a workspace keeps (ID photos, proofs, PDFs) goes to a private disk under its own folder,
| is checked by what the bytes ARE rather than what the client calls them, is stripped of hidden location data, and is
| only ever handed out through a link that expires in minutes.
*/

beforeEach(function () {
    // A real private folder (not Storage::fake), so that links are the real signed ones the real route serves.
    $this->root = storage_path('framework/testing/files-'.Str::random(10));
    config(['filesystems.disks.files.root' => $this->root]);
    Storage::forgetDisk(Files::DISK);
    [$this->user, $this->tenant] = owner();
    $this->customer = customerIn($this->tenant);
    $this->files = app(Files::class);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    Storage::forgetDisk(Files::DISK);
});

/** Stores [$bytes] as [$kind] for the workspace's customer, inside the workspace. */
function store(string $bytes, FileKind $kind = FileKind::PaymentProof, ?string $name = 'photo.jpg'): StoredFile
{
    return asTenant(test()->tenant, fn () => test()->files->put(upload($bytes, $name), $kind, test()->customer, test()->user));
}

describe('what is stored, and where', function () {
    it('keeps the file under the workspace and the kind, with an extension taken from what it is', function () {
        $file = store(jpegBytes(), FileKind::IdFront, 'whatever.png');

        expect($file->path)->toMatch('#^tenants/'.$this->tenant->id.'/id_front/'.$file->id.'\.jpg$#')
            ->and(Storage::disk(Files::DISK)->exists($file->path))->toBeTrue()
            ->and($file->mime)->toBe('image/jpeg')->and($file->kind)->toBe(FileKind::IdFront)
            ->and($file->tenant_id)->toBe($this->tenant->id)->and($file->uploaded_by_user_id)->toBe($this->user->id)
            ->and($file->subject_id)->toBe($this->customer->id)->and($file->size)->toBe(strlen((string) Storage::disk(Files::DISK)->get($file->path)));
    });

    it('accepts raw bytes as well as an upload', function () {
        $file = asTenant($this->tenant, fn () => $this->files->put(pdfBytes(), FileKind::ContractPdf, $this->customer, $this->user));

        expect($file->mime)->toBe('application/pdf')->and($file->path)->toEndWith('.pdf');
    });

    it('cannot be told a kind that would climb out of its folder', function () {
        expect(fn () => FileKind::from('../../etc'))->toThrow(ValueError::class)
            ->and(FileKind::tryFrom('id_front/../../x'))->toBeNull();

        foreach (FileKind::cases() as $kind) {
            expect($kind->value)->toMatch('/^[a-z_]+$/');
        }
    });

    it('refuses to store a file for a record of another workspace', function () {
        $other = customerIn(workspaceOn());

        expect(fn () => asTenant($this->tenant, fn () => $this->files->put(pdfBytes(), FileKind::Statement, $other, $this->user)))->toThrow(InvalidArgumentException::class);
        expect(StoredFile::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses to store anything when no workspace is active', function () {
        expect(fn () => $this->files->put(pdfBytes(), FileKind::Statement, $this->customer, $this->user))->toThrow(LogicException::class);
    });
});

describe('what is refused, and why it matters', function () {
    it('rejects a file by what it is, whatever it is called', function (string $bytes, string $name, FileKind $kind) {
        $error = validationErrors(fn () => store($bytes, $kind, $name));

        expect($error)->toHaveKey('file')->and(StoredFile::withoutGlobalScopes()->count())->toBe(0)->and(Storage::disk(Files::DISK)->allFiles())->toBe([]);
    })->with([
        'a web page called a photo' => ['<html><script>alert(1)</script></html>', 'x.jpg', FileKind::PaymentProof],
        'a script behind a double extension' => ['<?php echo "hi";', 'invoice.pdf.php', FileKind::ContractPdf],
        'a script called a PDF' => ['<?php system($_GET["c"]);', 'invoice.pdf', FileKind::ContractPdf],
        'a PDF called a picture, for a kind that takes pictures only' => [fn () => pdfBytes(), 'x.png', FileKind::Logo],
        'a picture called a PDF, for a kind that takes PDFs only' => [fn () => jpegBytes(), 'x.pdf', FileKind::Statement],
        'a text file called a picture' => ['just words', 'x.png', FileKind::IdFront],
        'nothing at all' => ['', 'x.jpg', FileKind::IdFront],
    ]);

    it('accepts a real PDF however its name ends, and stores it as a PDF', function () {
        $file = store(pdfBytes(), FileKind::PaymentProof, 'invoice.pdf.php');

        expect($file->path)->toEndWith('.pdf')->and($file->path)->not->toContain('php');
    });

    it('rejects a picture that only looks like one', function () {
        // The first bytes say JPEG; the rest is not an image at all.
        $error = validationErrors(fn () => store("\xFF\xD8\xFF\xE0".str_repeat('A', 200), FileKind::IdFront));

        expect($error)->toHaveKey('file');
    });

    it('rejects an image above 6 MB and a PDF above 10 MB, and takes one just under', function () {
        expect(validationErrors(fn () => store(substr(jpegBytes(), 0, 2).str_repeat("\x00", 6 * 1024 * 1024 + 1), FileKind::IdFront)))->toHaveKey('file')
            ->and(validationErrors(fn () => store(pdfBytes(10 * 1024 * 1024 + 1), FileKind::Statement)))->toHaveKey('file')
            ->and(store(pdfBytes(10 * 1024 * 1024 - 2000), FileKind::Statement)->size)->toBeLessThanOrEqual(10 * 1024 * 1024);
    });

    it('rejects a valid picture with an absurd number of pixels before trying to open it', function () {
        // A real PNG, 7000 x 7000 (49 million pixels, about 200 MB once opened) that is a few dozen KB on disk.
        expect(strlen(hugePng(7000)))->toBeLessThan(200_000);

        expect(validationErrors(fn () => store(hugePng(7000), FileKind::PaymentProof)))->toHaveKey('file');
    });

    it('still takes a large picture that is within the limit', function () {
        $file = store(hugePng(2000), FileKind::PaymentProof);

        expect(getimagesizefromstring((string) Storage::disk(Files::DISK)->get($file->path))[0])->toBe(2000);
    });
});

describe('hidden data in pictures', function () {
    it('strips EXIF, including where the photo was taken, and keeps the picture', function () {
        $input = jpegBytes(withExif: true);
        expect($input)->toContain('Exif')->and($input)->toContain('GPS:24.7136N');

        $file = store($input, FileKind::IdFront);
        $stored = (string) Storage::disk(Files::DISK)->get($file->path);

        expect($stored)->not->toContain('Exif')->and($stored)->not->toContain('GPS:')
            ->and(getimagesizefromstring($stored)[0])->toBe(20)->and(getimagesizefromstring($stored)[1])->toBe(10);
    });

    it('turns a sideways phone photo upright before dropping the orientation tag', function () {
        // Orientation 6: the phone was held upright, so the 20 x 10 picture must be shown 10 x 20.
        $file = store(jpegBytes(20, 10, orientation: 6), FileKind::IdFront);
        $size = getimagesizefromstring((string) Storage::disk(Files::DISK)->get($file->path));

        expect([$size[0], $size[1]])->toBe([10, 20]);
    });

    it('keeps the transparency of a PNG logo', function () {
        $image = imagecreatetruecolor(8, 8);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $file = store($png, FileKind::Logo, 'logo.png');
        $copy = imagecreatefromstring((string) Storage::disk(Files::DISK)->get($file->path));

        expect((imagecolorat($copy, 0, 0) >> 24) & 0x7F)->toBe(127);
    });

    it('does not re-encode a PDF: its bytes are kept as they came', function () {
        $pdf = pdfBytes();

        expect((string) Storage::disk(Files::DISK)->get(store($pdf, FileKind::Statement)->path))->toBe($pdf);
    });
});

describe('handing a file out', function () {
    it('makes a link that works for at most five minutes, whatever the caller asks', function () {
        $this->travelTo('2026-10-09 12:00:00');
        $file = store(jpegBytes());

        $url = $this->files->temporaryUrl($file, 3600);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect((int) $query['expires'])->toBeLessThanOrEqual(now()->addSeconds(300)->getTimestamp())
            ->and((int) $query['expires'])->toBeGreaterThan(now()->getTimestamp());
    });

    it('also caps the link of an S3-compatible disk at five minutes', function () {
        config(['filesystems.disks.files' => [
            'driver' => 's3', 'key' => 'test', 'secret' => 'test', 'region' => 'us-east-1', 'bucket' => 'qistas-files',
            'endpoint' => 'http://localhost:9000', 'use_path_style_endpoint' => true, 'throw' => false,
        ]]);
        Storage::forgetDisk(Files::DISK);
        $file = (new StoredFile)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'kind' => FileKind::IdFront, 'path' => 'tenants/'.$this->tenant->id.'/id_front/x.jpg']);

        $url = $this->files->temporaryUrl($file, 86400);

        preg_match('/X-Amz-Expires=(\d+)/', $url, $found);

        // The SDK counts the lifetime from the real clock, so a second may have passed: 299 is as good as 300. A day is not.
        expect((int) ($found[1] ?? 0))->toBeGreaterThanOrEqual(295)->toBeLessThanOrEqual(300)->and($url)->toContain('X-Amz-Signature=');
    });

    it('serves the file through the link, and refuses the same link once it has expired', function () {
        $this->travelTo('2026-10-09 12:00:00');
        $file = store(pdfBytes(), FileKind::Statement);
        $url = $this->files->temporaryUrl($file);

        $this->get($url)->assertOk();

        $this->travelTo(now()->addMinutes(6));
        $this->get($url)->assertForbidden();
    });

    it('refuses a link whose signature was tampered with', function () {
        $url = $this->files->temporaryUrl(store(pdfBytes(), FileKind::Statement));

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    });
});

describe('who may open it', function () {
    it('redirects a member of the workspace to a short-lived link', function () {
        $file = store(jpegBytes());

        $response = $this->actingAs($this->user)->get(route('app.files.show', $file));

        $response->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
        expect($response->headers->get('Location'))->toContain('expires=');
    });

    it('says not found for another workspace’s file, exactly as for one that does not exist', function () {
        $file = store(jpegBytes());
        [$stranger] = owner();

        $this->actingAs($stranger)->get(route('app.files.show', $file))->assertNotFound();
        $this->actingAs($stranger)->get(route('app.files.show', (string) Str::uuid()))->assertNotFound();
    });

    it('needs a signed-in person', function () {
        $this->get(route('app.files.show', store(jpegBytes())))->assertRedirect();
    });

    it('keeps ID documents from collectors and viewers, and lets them see other files', function (string $role, bool $canSeeId) {
        $member = memberAs($role, $this->tenant);
        $id = store(jpegBytes(), FileKind::IdFront);
        $proof = store(jpegBytes(), FileKind::PaymentProof);

        $this->actingAs($member)->get(route('app.files.show', $id))->assertStatus($canSeeId ? 302 : 403);
        $this->actingAs($member)->get(route('app.files.show', $proof))->assertRedirect();
    })->with([['owner', true], ['manager', true], ['accountant', true], ['collector', false], ['viewer', false]]);

    it('writes an audit row when an ID document is opened, and not for an ordinary file', function () {
        $id = store(jpegBytes(), FileKind::IdFront);
        $proof = store(jpegBytes(), FileKind::PaymentProof);

        $this->actingAs($this->user)->get(route('app.files.show', $proof))->assertRedirect();
        expect(AuditLog::where('action', 'file.viewed')->count())->toBe(0);

        $this->actingAs($this->user)->get(route('app.files.show', $id))->assertRedirect();
        $row = AuditLog::where('action', 'file.viewed')->sole();

        expect($row->subject_id)->toBe($id->id)->and($row->user_id)->toBe($this->user->id)->and($row->tenant_id)->toBe($this->tenant->id)
            ->and(json_encode($row->changes))->not->toContain($id->path);
    });

    it('writes an audit row when an ID document is stored', function () {
        store(jpegBytes(), FileKind::IdBack);
        store(jpegBytes(), FileKind::PaymentProof);

        expect(AuditLog::where('action', 'file.stored')->count())->toBe(1);
    });
});

describe('removing a file', function () {
    it('takes the object away and keeps the record, marked deleted', function () {
        $file = store(jpegBytes());

        $this->files->delete($file);

        $records = fn () => asTenant($this->tenant, fn () => [StoredFile::count(), StoredFile::withTrashed()->find($file->id)]);
        [$live, $kept] = $records();

        expect(Storage::disk(Files::DISK)->exists($file->path))->toBeFalse()
            ->and($live)->toBe(0)->and($kept)->not->toBeNull()->and($kept->trashed())->toBeTrue();

        $this->actingAs($this->user)->get(route('app.files.show', $file))->assertNotFound();
    });
});

describe('the workspace boundary', function () {
    it('never lists another workspace’s files', function () {
        store(jpegBytes());
        $other = workspaceOn();

        expect(asTenant($this->tenant, fn () => StoredFile::count()))->toBe(1)
            ->and(asTenant($other, fn () => StoredFile::count()))->toBe(0)
            ->and(app(CurrentTenant::class)->get())->toBeNull();
    });
});
