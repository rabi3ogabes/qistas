<?php

namespace App\Account;

use App\Models\User;
use App\Models\UserPhoto;
use App\Support\Audit;
use App\Support\ImageSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * A person's profile photo. What the file IS comes from its bytes, never its name; it is drawn again without any hidden
 * data (where and when a phone took it) and scaled down to what an avatar needs, then kept in place of the old one.
 */
final class ProfilePhoto
{
    /** The largest file taken in, and the box the kept picture fits in. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const SIZE = 400;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @throws ValidationException (field `photo`) when it is not a picture that can be read */
    public function store(User $user, UploadedFile $file): UserPhoto
    {
        $path = $file->isValid() ? $file->getRealPath() : false;
        if ($path === false) {
            throw $this->refuse(__('The file could not be uploaded. Please try again.'));
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw $this->refuse(__('This picture is larger than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $bytes = (string) file_get_contents($path);
        $mime = $bytes === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, self::MIMES, true)) {
            throw $this->refuse(__('Choose a photo: a JPEG, PNG or WebP picture.'));
        }

        // A WebP is kept as a JPEG: every app and every document can show it.
        $kept = $mime === 'image/png' ? 'image/png' : 'image/jpeg';
        $clean = ImageSanitizer::clean($bytes, $kept, self::SIZE, self::SIZE)
            ?? throw $this->refuse(__('This file could not be read as a picture.'));
        $size = getimagesizefromstring($clean) ?: throw $this->refuse(__('This file could not be read as a picture.'));

        $photo = UserPhoto::query()->find($user->id) ?? new UserPhoto;
        $photo->forceFill([
            'user_id' => $user->id,
            'mime' => $kept,
            'width' => $size[0],
            'height' => $size[1],
            'size' => strlen($clean),
            'sha256' => hash('sha256', $clean),
            'data' => base64_encode($clean),
        ])->save();

        Audit::record('profile.photo_changed', $user, ['size' => strlen($clean)], userId: $user->id);
        $user->setRelation('photo', $photo);

        return $photo;
    }

    public function remove(User $user): void
    {
        if (UserPhoto::query()->whereKey($user->id)->delete() > 0) {
            Audit::record('profile.photo_removed', $user, [], userId: $user->id);
        }
        $user->setRelation('photo', null);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => $message]);
    }
}
