<?php

namespace App\Import\Ai;

use App\Import\Fields;
use App\Support\ImageSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads a notebook photo or another app's PDF report into rows (Win Plan K9, decision D8), through the Claude API with
 * the owner's key. Claude is made to answer with one tool whose input is exactly the fields an Excel file has, so its
 * rows go into the very same trial run. A photo is drawn again first, without its hidden data, and no larger than the
 * reader needs. Nothing is saved here and nothing is guessed: what is not written stays empty.
 */
final class ClaudeReader
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** A report longer than this is split by the owner. */
    public const MAX_PAGES = 20;

    private const TOOL = 'record_customers';

    public function __construct(private readonly AiSettings $settings) {}

    /** How many pages a file is: a photo is one; a PDF counts its pages. */
    public static function pages(string $bytes, string $mime): int
    {
        if ($mime !== 'application/pdf') {
            return 1;
        }

        return max(1, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes));
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     *
     * @throws ValidationException (field `file`) when the page could not be read
     */
    public function read(string $bytes, string $mime): array
    {
        $source = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($this->photo($bytes, $mime))]];

        $fields = Fields::keys();
        $properties = array_fill_keys($fields, ['type' => ['string', 'null']]);

        try {
            $response = Http::withHeaders(['x-api-key' => (string) $this->settings->key(), 'anthropic-version' => '2023-06-01'])
                ->timeout(120)
                ->post(self::ENDPOINT, [
                    'model' => $this->settings->model(),
                    'max_tokens' => 8192,
                    'system' => 'You copy the customer records of a small shop that sells on instalments, from a photo of its notebook or a PDF report from another app, into rows. Copy exactly what is written; never compute, round, translate or guess. Leave a field empty when it is not written.',
                    'tools' => [[
                        'name' => self::TOOL,
                        'description' => 'Record every customer or contract line found, one row each, in the order they appear.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties]]],
                            'required' => ['rows'],
                        ],
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
                    'messages' => [[
                        'role' => 'user',
                        'content' => [$source, ['type' => 'text', 'text' => self::instructions()]],
                    ]],
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            throw $this->unreadable();
        }

        // Only what came through the tool it was asked to use; any other block is ignored.
        $input = null;
        foreach (is_array($response) && is_array($response['content'] ?? null) ? $response['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL) {
                $input = $block['input'] ?? null;
                break;
            }
        }
        if (! is_array($input) || ! is_array($input['rows'] ?? null)) {
            throw $this->unreadable();
        }

        $rows = [];
        foreach ($input['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = array_map(fn (string $field) => is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '', $fields);
            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('No customers could be read on this page. Try a clearer photo, or an Excel file.')]);
        }

        return ['headers' => $fields, 'rows' => $rows];
    }

    private static function instructions(): string
    {
        return implode("\n", [
            'Read every customer or contract line on this page. For each, fill what is written:',
            'name: the customer; phone: their phone number exactly as written; national_id: an ID or iqama number;',
            'address; item: what was sold; total: the price or contract total; paid: what was paid so far;',
            'balance: what is still owed; installments: the number of instalments; installment_amount: one instalment;',
            'first_due: the first or next due date as written; start_date: the contract date; notes: anything else on the line.',
            'Write amounts in figures as written (Arabic or Western digits are both fine). Skip headings and totals rows.',
        ]);
    }

    /** The photo drawn again, without its hidden data, at most 2000 pixels: enough to read, little to send. */
    private function photo(string $bytes, string $mime): string
    {
        $clean = ImageSanitizer::clean($bytes, $mime === 'image/png' ? 'image/png' : 'image/jpeg', 2000, 2000);
        if ($clean === null) {
            throw ValidationException::withMessages(['file' => __('This file could not be read as a picture.')]);
        }
        if ($mime === 'image/png') {
            $image = imagecreatefromstring($clean);
            ob_start();
            imagejpeg($image === false ? imagecreatetruecolor(1, 1) : $image, null, 88);
            $clean = (string) ob_get_clean();
        }

        return $clean;
    }

    private function unreadable(): ValidationException
    {
        return ValidationException::withMessages(['file' => __('The page could not be read just now. Try again in a minute, or bring an Excel file.')]);
    }
}
