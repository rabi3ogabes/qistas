<?php

namespace App\Support;

use App\Models\StoredFile;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * The only way files get in and out of a workspace's private storage (the `files` disk: S3 or a Supabase bucket in
 * production, a private folder in development).
 *
 *  - What a file IS is read from its bytes. The name the client gave is never used, not even for the extension.
 *  - Pictures are drawn again without their hidden data (see ImageSanitizer).
 *  - Files live under `tenants/{workspace}/{kind}/{id}.{ext}`, and nothing is ever served from a public address:
 *    a person is sent to a link that expires within five minutes.
 */
final class Files
{
    public const DISK = 'files';

    /** No link to a file lives longer than this, whatever a caller asks for. */
    public const LINK_SECONDS = 300;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    /**
     * Stores a file for a record of the active workspace.
     *
     * @throws ValidationException (field `file`) when it is not an allowed type, is too large, or is not a real picture
     * @throws LogicException when no workspace is active
     * @throws InvalidArgumentException when the record belongs to another workspace
     */
    public function put(UploadedFile|string $content, FileKind $kind, Model $subject, User $by): StoredFile
    {
        $tenant = app(CurrentTenant::class)->get() ?? throw new LogicException('Files are kept for a workspace, and none is active.');

        if ($subject->offsetExists('tenant_id') && $subject->getAttribute('tenant_id') !== $tenant->id) {
            throw new InvalidArgumentException('A file can only be attached to a record of the same workspace.');
        }

        $bytes = $this->bytes($content);
        $mime = $this->sniff($bytes);

        if (! in_array($mime, $kind->mimes(), true)) {
            throw $this->refuse(__('This type of file is not allowed here.'));
        }

        if (strlen($bytes) > FileKind::maxBytes($mime)) {
            throw $this->refuse($mime === 'application/pdf'
                ? __('This PDF is larger than :mb MB.', ['mb' => FileKind::PDF_BYTES / 1024 / 1024])
                : __('This picture is larger than :mb MB.', ['mb' => FileKind::IMAGE_BYTES / 1024 / 1024]));
        }

        if ($mime !== 'application/pdf') {
            $bytes = ImageSanitizer::clean($bytes, $mime) ?? throw $this->refuse(__('This file could not be read as a picture.'));
        }

        $id = (string) Str::uuid();
        $path = 'tenants/'.$tenant->id.'/'.$kind->value.'/'.$id.'.'.self::EXTENSIONS[$mime];

        if (! Storage::disk(self::DISK)->put($path, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('The file could not be written to storage.');
        }

        $file = (new StoredFile)->forceFill([
            'id' => $id,
            'kind' => $kind,
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'uploaded_by_user_id' => $by->getKey(),
        ]);
        $file->save();

        if ($kind->audited()) {
            Audit::record('file.stored', $file, ['kind' => $kind->value], userId: $by->getKey());
        }

        return $file;
    }

    /** A link to [$file] that expires in [$seconds] (never more than five minutes). */
    public function temporaryUrl(StoredFile $file, int $seconds = self::LINK_SECONDS): string
    {
        $seconds = max(1, min($seconds, self::LINK_SECONDS));

        return Storage::disk(self::DISK)->temporaryUrl($file->path, now()->addSeconds($seconds));
    }

    /** Takes the object out of storage and marks the record deleted (the record stays). */
    public function delete(StoredFile $file): void
    {
        Storage::disk(self::DISK)->delete($file->path);
        $file->delete();
    }

    private function bytes(UploadedFile|string $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! $content->isValid() || ($path = $content->getRealPath()) === false) {
            throw $this->refuse(__('The file could not be uploaded. Please try again.'));
        }

        return (string) file_get_contents($path);
    }

    /** What the bytes are, from their own first bytes. */
    private function sniff(string $bytes): string
    {
        if ($bytes === '') {
            return 'application/x-empty';
        }

        return (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
