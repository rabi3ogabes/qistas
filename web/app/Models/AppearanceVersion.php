<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One state of the product's look: the working draft, or a published version. A published version is history and is
 * never changed or removed; "restore" and "reset" publish new versions instead. Written only by App\Theme\Appearance.
 *
 * @property string $id
 * @property int|null $version
 * @property string $status
 * @property array<string, mixed> $pins
 * @property array<string, mixed>|null $tokens
 * @property array<string, string> $images
 * @property array<string, array<string, mixed>> $banners
 * @property list<array<string, string>>|null $repaired
 * @property string|null $note
 * @property string|null $created_by_user_id
 * @property string|null $published_by_user_id
 * @property Carbon|null $published_at
 */
#[Fillable(['pins', 'images', 'banners'])]
class AppearanceVersion extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') === 'published') {
                throw new LogicException('A published version is history and cannot be changed.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->status === 'published') {
                throw new LogicException('A published version is history and cannot be removed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'pins' => 'array', 'tokens' => 'array', 'images' => 'array', 'banners' => 'array', 'repaired' => 'array',
            'version' => 'integer', 'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** @return array<string, mixed> */
    public function pins(): array
    {
        return $this->pins ?? [];
    }

    /** @return array<string, string> */
    public function images(): array
    {
        return $this->images ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function banners(): array
    {
        return $this->banners ?? [];
    }

    /** @return list<array<string, string>> */
    public function repaired(): array
    {
        return $this->repaired ?? [];
    }
}
