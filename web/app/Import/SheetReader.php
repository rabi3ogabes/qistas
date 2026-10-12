<?php

namespace App\Import;

use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Reads the first sheet of an Excel file, or a CSV (comma, semicolon or tab; a byte-order mark is fine), into its
 * header row and its rows, every cell as text. A date cell becomes "2026-03-15"; a number keeps its figures. Empty rows
 * are left out, and a file is read up to MAX_ROWS rows.
 */
final class SheetReader
{
    /** No shop's file is larger; a bigger one is something else. */
    public const MAX_ROWS = 5000;

    /**
     * @param  string  $kind  xlsx | csv
     * @return array{headers: list<string>, rows: list<list<string>>}
     *
     * @throws ValidationException (field `file`) when it cannot be read as a spreadsheet
     */
    public function read(string $path, string $kind): array
    {
        try {
            $reader = $kind === 'csv' ? new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->delimiter($path))) : new XlsxReader;
            $reader->open($path);

            $headers = null;
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn (mixed $value): string => $this->text($value), $row->toArray());
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn (string $h): string => trim(ltrim($h, "\u{FEFF}")), $cells);

                        continue;
                    }
                    $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, max(count($headers), count($cells)));
                    if (count($rows) > self::MAX_ROWS) {
                        break 2;
                    }
                }

                break;
            }
            $reader->close();
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('Choose an Excel (.xlsx) or CSV file.')]);
        }

        if ($headers === null || $rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no rows to bring in.')]);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }

    /** The CSV's separator, from its first line: whichever of comma, semicolon or tab it uses most. */
    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
