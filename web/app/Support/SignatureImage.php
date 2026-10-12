<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A customer's signature as it is kept (Win Plan K5): a PNG, drawn again by the server so nothing rides along with it,
 * transparent where it was, and at most 1200 x 600. The pad on the web sends a data URL; the app sends the file.
 */
final class SignatureImage
{
    /** A drawing larger than this is not a signature. */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * @return string the clean PNG, base64
     *
     * @throws ValidationException (field `file`) when it is not a PNG picture
     */
    public static function clean(string $bytes): string
    {
        $mime = $bytes === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime !== 'image/png' || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('A signature must be a PNG picture.')]);
        }

        $clean = ImageSanitizer::clean($bytes, 'image/png', 1200, 600)
            ?? throw ValidationException::withMessages(['file' => __('A signature must be a PNG picture.')]);

        return base64_encode($clean);
    }

    /**
     * The bytes of a drawing the web pad sent as `data:image/png;base64,...`.
     *
     * @throws ValidationException (field `file`) when it is not one
     */
    public static function fromDrawing(string $drawing): string
    {
        $prefix = 'data:image/png;base64,';
        $bytes = str_starts_with($drawing, $prefix) ? base64_decode(substr($drawing, strlen($prefix)), true) : false;

        return $bytes === false || $bytes === '' ? throw ValidationException::withMessages(['file' => __('Draw the signature again, then save it.')]) : $bytes;
    }
}
