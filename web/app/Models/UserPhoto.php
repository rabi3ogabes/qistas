<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's profile photo, already drawn again and scaled down (see App\Account\ProfilePhoto, the only writer). It
 * belongs to the person, not to a workspace: the same face in every business they work in.
 *
 * @property string $user_id
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string $sha256
 * @property string $data base64
 */
class UserPhoto extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The picture as a source the app or a page can show without another request. */
    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.$this->data;
    }

    public function bytes(): string
    {
        return (string) base64_decode($this->data, true);
    }
}
