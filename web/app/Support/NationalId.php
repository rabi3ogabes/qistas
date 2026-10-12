<?php

namespace App\Support;

/** An ID number for a screen or a document: everything but the last three digits hidden. The full number never leaves. */
final class NationalId
{
    public static function mask(?string $id): ?string
    {
        $id = (string) $id;
        $length = mb_strlen($id);

        if ($length === 0) {
            return null;
        }
        if ($length <= 3) {
            return '••';
        }

        return str_repeat('•', max(2, min(6, $length - 3))).mb_substr($id, -3);
    }
}
