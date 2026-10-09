<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\AppearanceAsset;
use App\Models\User;
use App\Support\ImageSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only way a brand picture gets in. What a file IS comes from its own bytes (never its name); only JPEG and PNG are
 * taken (never a vector picture, which can carry script); the picture is drawn again without any hidden data and scaled
 * down to what its slot needs. It is kept in the database, so it survives a host whose disk is thrown away.
 */
final class BrandImages
{
    /** What an upload may weigh before it is processed. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** slot => [the widest, the tallest] a picture there ever needs to be. */
    public const SLOTS = ['logo' => [600, 240], 'logo_dark' => [600, 240], 'hero' => [2000, 1200], 'banner' => [1600, 700]];

    /**
     * @throws ValidationException (field `file`) when the file is not an allowed picture
     * @throws InvalidArgumentException when the slot is not one of SLOTS
     */
    public function store(UploadedFile|string $file, string $slot, User $by): AppearanceAsset
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new InvalidArgumentException("There is no picture slot [{$slot}].");
        }

        $bytes = $this->bytes($file);

        if (strlen($bytes) > self::MAX_BYTES) {
            throw $this->refuse(__('This picture is larger than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $mime = $bytes === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw $this->refuse(__('This type of file is not allowed here.'));
        }

        [$maxWidth, $maxHeight] = self::SLOTS[$slot];
        $clean = ImageSanitizer::clean($bytes, $mime, $maxWidth, $maxHeight) ?? throw $this->refuse(__('This file could not be read as a picture.'));
        $size = getimagesizefromstring($clean) ?: throw $this->refuse(__('This file could not be read as a picture.'));

        $asset = (new AppearanceAsset)->forceFill([
            'slot' => $slot,
            'mime' => $mime,
            'width' => $size[0],
            'height' => $size[1],
            'size' => strlen($clean),
            'sha256' => hash('sha256', $clean),
            'data' => base64_encode($clean),
            'created_by_user_id' => $by->getKey(),
        ]);
        $asset->save();

        return $asset;
    }

    private function bytes(UploadedFile|string $file): string
    {
        if (is_string($file)) {
            return $file;
        }

        if (! $file->isValid() || ($path = $file->getRealPath()) === false) {
            throw $this->refuse(__('The file could not be uploaded. Please try again.'));
        }

        return (string) file_get_contents($path);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
