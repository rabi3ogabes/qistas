<?php

/*
| Picture and document fixtures shared by the tests (file storage, brand pictures): real JPEG, PNG and PDF bytes made on the spot.
*/

use Illuminate\Http\UploadedFile;

/** A real JPEG, [$width] x [$height], optionally with an EXIF segment (a GPS-style string and an orientation) injected after the start marker. */
function jpegBytes(int $width = 20, int $height = 10, ?int $orientation = null, bool $withExif = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    if (! $withExif && $orientation === null) {
        return $jpeg;
    }

    // APP1 "Exif" segment: big-endian TIFF header, one IFD0 entry (orientation) and a marker string a leak would show.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation ?? 1)."\x00\x00"."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff.'GPS:24.7136N,46.6753E';

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

function pngBytes(): string
{
    $image = imagecreatetruecolor(12, 12);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A valid solid-colour RGB PNG, [$side] x [$side], built a row at a time so the picture is never held in memory. */
function hugePng(int $side): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $row = str_repeat(chr(0), 1 + $side * 3);
    $deflate = deflate_init(ZLIB_ENCODING_DEFLATE);
    $idat = '';

    for ($y = 0; $y < $side; $y++) {
        $idat .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
    }
    $idat .= deflate_add($deflate, '', ZLIB_FINISH);

    return pack('C*', 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A)
        .$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0)).$chunk('IDAT', $idat).$chunk('IEND', '');
}

function pdfBytes(int $padding = 0): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n".str_repeat(' ', $padding);
}

function upload(string $bytes, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'qst');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}
