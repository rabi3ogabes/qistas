<?php

namespace App\Exports;

/** One sheet of an export: its name (for Excel and the CSV file), the column headings and the rows. */
final class Sheet
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $headers,
        public readonly iterable $rows,
    ) {}
}
