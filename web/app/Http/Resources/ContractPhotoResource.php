<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\StoredFile;
use App\Support\Files;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoredFile
 *
 * A contract photo (Win Plan K5). An ordinary photo comes with a link that works for five minutes. An ID photo is
 * `private`: it has no link here, and is opened one at a time through GET /files/{file}, which is audited. The
 * controller leaves out the photos a person may not open at all.
 */
class ContractPhotoResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $private = $this->kind->audited();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->kind->photoLabel(),
            'mime' => $this->mime,
            'size' => $this->size,
            'private' => $private,
            'url' => $private ? null : app(Files::class)->temporaryUrl($this->resource),
            'created_at' => $this->moment($this->created_at),
        ];
    }
}
