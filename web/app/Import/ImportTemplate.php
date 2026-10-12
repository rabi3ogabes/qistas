<?php

namespace App\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * An empty sheet to fill in (Win Plan K9): the column names an import recognises, in the reader's language, right to left
 * for Arabic and Urdu. No example row, so nothing made up is ever brought in by mistake.
 */
final class ImportTemplate
{
    /** The columns, in the order a shop's notebook usually has them. */
    private const COLUMNS = ['name', 'phone', 'national_id', 'address', 'item', 'total', 'paid', 'installments', 'first_due', 'balance', 'notes'];

    public static function xlsx(string $language): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qtpl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(mb_substr(__('Customers', [], $language), 0, 31));
            $sheet->setSheetView(new SheetView(rightToLeft: in_array($language, (array) config('qistas.rtl_locales'), true), freezeRow: 2));
            $writer->addRow(Row::fromValuesWithStyle(array_map(fn (string $field) => Fields::header($field, $language), self::COLUMNS), (new Style)->withFontBold(true)));
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
