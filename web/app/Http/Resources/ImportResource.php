<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Import\Fields;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 *
 * An import (Win Plan K9): its columns and what each was taken as (with the fields to choose from), every row with what
 * it would do or did and its problems by column, and the totals. ID numbers only ever appear masked.
 */
class ImportResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'filename' => $this->filename,
            'columns' => array_map(fn (int $index, string $header) => ['index' => $index, 'header' => $header, 'field' => $this->mapping[$index] ?? null], array_keys($this->headers), $this->headers),
            'fields' => array_map(fn (string $key) => ['key' => $key, 'label' => Fields::label($key)], Fields::keys()),
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['customer' => true]), $this->rows),
            'summary' => $this->summary,
            'created_at' => $this->moment($this->created_at),
            'confirmed_at' => $this->moment($this->confirmed_at),
        ];
    }
}
