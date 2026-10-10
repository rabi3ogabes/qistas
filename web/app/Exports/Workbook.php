<?php

namespace App\Exports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Writes sheets as one Excel file, or as CSV files in a zip. Each CSV starts with a UTF-8 byte-order mark, so Excel opens
 * Arabic and Urdu as they are. Written through a temporary file (the system's temp folder, which a host always lets an
 * app write to), never kept.
 */
final class Workbook
{
    /**
     * @param  list<Sheet>  $sheets
     * @return array{0: string, 1: array<string, int>} the file's bytes, and how many rows each sheet has
     */
    public static function xlsx(array $sheets, bool $rtl): array
    {
        $path = self::temporary('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $counts = [];

        try {
            foreach ($sheets as $index => $sheet) {
                $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $current->setName(mb_substr($sheet->name, 0, 31));
                // The headings stay in view as the rows scroll; Arabic and Urdu read from the right.
                $current->setSheetView(new SheetView(rightToLeft: $rtl, freezeRow: 2));

                $writer->addRow(Row::fromValuesWithStyle($sheet->headers, $bold));
                $counts[$sheet->key] = 0;
                foreach ($sheet->rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                    $counts[$sheet->key]++;
                }
            }
            $writer->close();

            return [(string) file_get_contents($path), $counts];
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<Sheet>  $sheets
     * @return array{0: string, 1: array<string, int>} the zip's bytes, and how many rows each sheet has
     */
    public static function csvZip(array $sheets): array
    {
        $path = self::temporary('zip');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export zip could not be written.');
        }
        $counts = [];

        try {
            foreach ($sheets as $sheet) {
                $csv = fopen('php://temp', 'w+') ?: throw new RuntimeException('No room for the CSV.');
                fwrite($csv, "\xEF\xBB\xBF");
                fputcsv($csv, $sheet->headers, escape: '');
                $counts[$sheet->key] = 0;
                foreach ($sheet->rows as $row) {
                    fputcsv($csv, array_map(fn (mixed $cell) => $cell === null ? '' : $cell, $row), escape: '');
                    $counts[$sheet->key]++;
                }
                rewind($csv);
                $zip->addFromString($sheet->key.'.csv', (string) stream_get_contents($csv));
                fclose($csv);
            }
            $zip->close();

            return [(string) file_get_contents($path), $counts];
        } finally {
            @unlink($path);
        }
    }

    private static function temporary(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qistas-export-');
        if ($path === false) {
            throw new RuntimeException('No temporary file for the export.');
        }
        @unlink($path);

        return $path.'.'.$extension;
    }
}
