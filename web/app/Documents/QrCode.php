<?php

namespace App\Documents;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** A QR code as a self-contained SVG: what a printed statement carries so that a phone can check it is genuine. */
final class QrCode
{
    public static function svg(string $text, int $size = 256): string
    {
        $style = new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(11, 31, 68)));

        return (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString($text);
    }

    /** The same code as an <img> source that a PDF engine or a page can show. */
    public static function dataUri(string $text, int $size = 256): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $size));
    }
}
