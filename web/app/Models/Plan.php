<?php

namespace App\Models;

use App\Entitlements\Feature;
use App\Entitlements\FeatureType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

// key and is_default are set by trusted code only.
#[Fillable(['name', 'description', 'sort_order', 'is_public'])]
class Plan extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::creating(function (Plan $plan): void {
            $plan->key ??= self::uniqueKey($plan->name);
        });
    }

    /** The plan every new workspace starts on. */
    public static function default(): self
    {
        return static::where('is_default', true)->orderBy('sort_order')->firstOrFail();
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * Set what this plan gives for one feature. This is the one write path for the admin matrix.
     *
     * @param  ?int  $limit  null = unlimited. Only counted features (limit, quota) may have one.
     */
    public function setFeature(Feature $feature, bool $enabled, ?int $limit = null): PlanFeature
    {
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('A limit cannot be negative.');
        }
        if ($limit !== null && $feature->type() === FeatureType::Toggle) {
            throw new InvalidArgumentException("[{$feature->value}] is an on/off feature and cannot have a limit.");
        }

        return $this->features()->updateOrCreate(
            ['feature_key' => $feature->value],
            ['enabled' => $enabled, 'limit_value' => $enabled ? $limit : null],
        );
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_public' => 'boolean', 'sort_order' => 'integer'];
    }

    private static function uniqueKey(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $key = $base;

        while (static::where('key', $key)->exists()) {
            $key = $base.'-'.Str::lower(Str::random(4));
        }

        return $key;
    }
}
