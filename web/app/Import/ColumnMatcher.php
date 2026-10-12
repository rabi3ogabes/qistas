<?php

namespace App\Import;

/**
 * Which field each column of a file is (Win Plan K9). A header is compared once letters are made alike: case, accents,
 * the Arabic hamza and taa marbuta forms, diacritics, tatweel and punctuation. An exact name wins; otherwise the
 * longest known name inside the header ("Mobile no." is a phone). Each field goes to one column at most, the first.
 */
final class ColumnMatcher
{
    /**
     * @param  list<string>  $headers
     * @return array<int, string|null> column index => field, or null when it is not recognised
     */
    public static function match(array $headers): array
    {
        $names = [];
        foreach (Fields::NAMES as $field => $languages) {
            foreach ($languages as $list) {
                foreach ($list as $name) {
                    $names[self::normalise($name)] = $field;
                }
            }
        }

        $mapping = [];
        $taken = [];
        foreach ($headers as $index => $header) {
            $key = self::normalise($header);
            $field = $names[$key] ?? self::longestInside($key, $names);

            if ($field !== null && ! isset($taken[$field])) {
                $mapping[$index] = $field;
                $taken[$field] = true;
            } else {
                $mapping[$index] = null;
            }
        }

        return $mapping;
    }

    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', '’' => '\'', 'ـ' => '']);
        $ascii = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_D) : $text;
        $text = (string) preg_replace('/\p{Mn}/u', '', $ascii);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /** @param  array<string, string>  $names */
    private static function longestInside(string $header, array $names): ?string
    {
        $best = null;
        $length = 0;
        foreach ($names as $name => $field) {
            if (mb_strlen($name) > $length && preg_match('/(^| )'.preg_quote($name, '/').'( |$)/u', $header) === 1) {
                $best = $field;
                $length = mb_strlen($name);
            }
        }

        return $best;
    }
}
