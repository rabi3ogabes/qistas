<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A brand picture (logo, hero, welcome-banner picture), already re-encoded and resized, kept as base64 in the database.
 * Created only by App\Theme\BrandImages; never changed afterwards, so its address can be cached for ever.
 *
 * @property string $id
 * @property string $slot
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string $sha256
 * @property string $data
 * @property Carbon|null $created_at
 */
class AppearanceAsset extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** The picture's bytes. */
    public function bytes(): string
    {
        return (string) base64_decode($this->data, true);
    }

    /** Where the picture is served from: an address that never changes. */
    public function url(): string
    {
        return route('brand.asset', $this);
    }
}
