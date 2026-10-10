<?php

use Smalot\PdfParser\Parser;

/*
| Reading a rendered PDF back, the way a person's copy-and-paste or a search engine would see it, so that "the text is
| there and in the right order" is checked, not assumed.
*/

/** The text of a PDF, as a parser extracts it. */
function pdfText(string $pdf): string
{
    return (new Parser)->parseContent($pdf)->getText();
}

function pdfPages(string $pdf): int
{
    return count((new Parser)->parseContent($pdf)->getPages());
}

/** Shaped Arabic comes back from a parser as presentation forms, and sometimes visually reversed: compare in a neutral form. */
function normalised(string $text): string
{
    return preg_replace('/\s+/u', '', Normalizer::normalize($text, Normalizer::FORM_KC)) ?? '';
}

function reversedByCharacter(string $text): string
{
    return implode('', array_reverse(mb_str_split($text)));
}

function containsArabic(string $text, string $expected): bool
{
    $haystack = normalised($text);
    $needle = normalised($expected);
    // Reversed before it is normalised: a ligature glyph (شر drawn as one shape) must turn round as one piece.
    $turned = normalised(reversedByCharacter($text));

    return str_contains($haystack, $needle) || str_contains($turned, $needle) || str_contains($haystack, reversedByCharacter($needle));
}

/** How many pictures (logos, signatures) a PDF carries. A transparent picture brings a second, hidden one (its mask): not counted. */
function pdfImages(string $pdf): int
{
    $images = (new Parser)->parseContent($pdf)->getObjectsByType('XObject', 'Image');
    $masks = count(array_filter($images, fn ($image) => $image->getHeader()->has('SMask')));

    return count($images) - $masks;
}

/** The first page's width and height, in millimetres. */
function pdfPageSize(string $pdf): array
{
    $box = (new Parser)->parseContent($pdf)->getPages()[0]->getDetails()['MediaBox'];

    return [round(($box[2] - $box[0]) / 72 * 25.4), round(($box[3] - $box[1]) / 72 * 25.4)];
}
