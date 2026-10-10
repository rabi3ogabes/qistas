<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's logo or signature for its documents, already re-encoded and scaled down (see
 * App\Documents\BusinessProfile, the only writer).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $slot logo | signature
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string $sha256
 * @property string $data base64
 */
#[Fillable(['slot'])]
class BusinessAsset extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    /** The picture as a source a PDF engine or a page can show without another request. */
    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.$this->data;
    }
}
