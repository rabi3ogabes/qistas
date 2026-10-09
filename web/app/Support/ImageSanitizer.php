<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Makes a picture safe to keep: it is decoded and drawn again, so every hidden part is gone: EXIF (where and when a
 * phone took it, and which phone), embedded profiles, comments and anything appended after the picture. A phone photo
 * that is only "sideways" by an orientation tag is turned upright first, or an ID would come back lying on its side.
 */
final class ImageSanitizer
{
    /** More than this many pixels would need more memory to open than a request may use. */
    public const MAX_PIXELS = 40_000_000;

    /**
     * @return string|null The clean picture, or null when [$bytes] cannot be opened as a [$mime] picture.
     */
    public static function clean(string $bytes, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is needed to store pictures.');
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $image = self::upright($image, self::orientation($bytes));
        }

        ob_start();
        $written = $mime === 'image/png'
            ? self::png($image)
            : imagejpeg($image, null, 90);
        $clean = (string) ob_get_clean();

        return $written && $clean !== '' ? $clean : null;
    }

    private static function png(GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, null, 6);
    }

    /** Turns the picture the way its EXIF orientation says (1 to 4, 6 and 8: what phones and cameras write). */
    private static function upright(GdImage $image, int $orientation): GdImage
    {
        $turned = match ($orientation) {
            2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : false,
            3 => imagerotate($image, 180, 0),
            4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : false,
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned instanceof GdImage ? $turned : $image;
    }

    /** The EXIF orientation of a JPEG (1 when there is none, or it cannot be read), without needing the exif extension. */
    private static function orientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        $at = 2;

        while ($at + 4 <= $length && $jpeg[$at] === "\xFF") {
            $marker = ord($jpeg[$at + 1]);

            if ($marker === 0xDA || $marker === 0xD9) {
                break; // the picture itself starts: no more headers
            }

            $segment = unpack('n', substr($jpeg, $at + 2, 2));
            $size = is_array($segment) ? (int) $segment[1] : 0;

            if ($size < 2) {
                break;
            }

            if ($marker === 0xE1 && substr($jpeg, $at + 4, 6) === "Exif\x00\x00") {
                return self::tiffOrientation(substr($jpeg, $at + 10, $size - 8));
            }

            $at += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);

        if (strlen($tiff) < 14 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }

        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $read = function (string $format, int $from, int $bytes) use ($tiff): ?int {
            $chunk = substr($tiff, $from, $bytes);
            $value = strlen($chunk) === $bytes ? unpack($format, $chunk) : false;

            return is_array($value) ? (int) $value[1] : null;
        };

        $ifd = $read($long, 4, 4);
        $entries = $ifd === null ? null : $read($short, $ifd, 2);

        if ($ifd === null || $entries === null) {
            return 1;
        }

        for ($i = 0; $i < min($entries, 64); $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($read($short, $entry, 2) === 0x0112) {
                $orientation = $read($short, $entry + 8, 2);

                return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
            }
        }

        return 1;
    }
}
