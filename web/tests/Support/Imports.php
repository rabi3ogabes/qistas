<?php

/*
| Helpers for the import tests (Win Plan K9): a real XLSX made on the spot, sending a file, and the trial run's rows.
*/

use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** @param  list<list<mixed>>  $rows  the first is the header row */
function xlsxOf(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function sendBooks(string $bytes, string $name = 'customers.xlsx'): TestResponse
{
    return test()->post('/api/v1/imports', ['file' => upload($bytes, $name)], ['Accept' => 'application/json']);
}

/** @return array<int, array<string, mixed>> the trial's rows by their row number in the sheet */
function rowsOf(TestResponse $response): array
{
    return collect($response->json('data.rows'))->keyBy('row')->all();
}
