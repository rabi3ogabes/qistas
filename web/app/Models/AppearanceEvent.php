<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seasonal look: for its countries (none = everyone), from its first to its last day in its own time zone, on its
 * places (website, web app, Android app). Laid over the published look while it lasts. Written only by
 * App\Theme\Appearance.
 *
 * @property string $id
 * @property string $name
 * @property string|null $preset
 * @property list<string> $countries
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $timezone
 * @property list<string> $surfaces
 * @property array<string, mixed> $pins
 * @property array<string, string> $images
 * @property array<string, array<string, mixed>> $banners
 * @property string $status
 * @property int $revision
 * @property list<array<string, string>>|null $repaired
 * @property string|null $created_by_user_id
 * @property string|null $updated_by_user_id
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class AppearanceEvent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'countries' => 'array', 'surfaces' => 'array', 'pins' => 'array', 'images' => 'array', 'banners' => 'array', 'repaired' => 'array',
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'revision' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** The date in the event's own time zone at [$now]. */
    public function localDate(CarbonInterface $now): string
    {
        return CarbonImmutable::instance($now)->setTimezone($this->timezone)->toDateString();
    }

    /** Whether it is scheduled and today, in its own time zone, falls between its first and last day. */
    public function isOnAt(CarbonInterface $now): bool
    {
        return $this->stateAt($now) === 'live';
    }

    /** Whether it is meant for a visitor from [$country] on this [$surface]. */
    public function appliesTo(?string $country, string $surface): bool
    {
        if (! in_array($surface, $this->surfaces ?? [], true)) {
            return false;
        }

        $countries = $this->countries ?? [];

        return $countries === [] || ($country !== null && in_array($country, $countries, true));
    }

    /** draft (only the admin sees it), scheduled (its days are ahead), live (on now) or ended. */
    public function stateAt(CarbonInterface $now): string
    {
        if ($this->status !== 'scheduled') {
            return 'draft';
        }

        $today = $this->localDate($now);

        return match (true) {
            $today < $this->starts_on->toDateString() => 'scheduled',
            $today > $this->ends_on->toDateString() => 'ended',
            default => 'live',
        };
    }

    /** The first moment after its last day, in its own time zone, as UTC. */
    public function until(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->ends_on->toDateString().' 00:00:00', $this->timezone)->addDay()->utc();
    }

    /** The first moment of its first day, in its own time zone, as UTC. */
    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->starts_on->toDateString().' 00:00:00', $this->timezone)->utc();
    }

    /**
     * Whether this event is shown instead of [$other] where both are on: the one aimed at fewer countries (everyone is
     * the widest), then the one that started later, then the one changed last.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /** @return array{0: int, 1: int, 2: int} the smaller wins; a later start and a later edit are negated to sort first */
    public function rank(): array
    {
        $reach = ($this->countries ?? []) === [] ? PHP_INT_MAX : count($this->countries);

        return [$reach, -(int) $this->starts_on->format('Ymd'), -(int) ($this->updated_at?->getTimestamp() ?? 0)];
    }

    /** How many days it lasts, first and last included. */
    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
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
}
