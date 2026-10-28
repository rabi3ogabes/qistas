<?php

namespace App\Http\Resources;

use App\Domain\DayClose\SummaryText;
use App\Http\Resources\Concerns\Presents;
use App\Models\DayClose;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * @mixin DayClose
 *
 * One close of one day (daily-habit wave A, H4), as it was signed: the cash counted against what was recorded, the day's
 * figures kept with it, who closed it and, once it has been, who reopened it and why. `summary_text` is the sentence to
 * share, in the language of the request, and `summary_url` the signed PDF. A close is a record: nothing here is a
 * customer's identity, and the people are only an id and a name (null once someone has left the team).
 *
 * A caller with a page of closes may set `shop_name` on each (the shop's name in the language in use), so the sentences of
 * the page do not each look the shop up.
 */
class DayCloseResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DayClose $close */
        $close = $this->resource;
        /** @var array{by_method?: array<string, string>, count?: int, per_member?: array<string, array{name: string, cash: string, count: int}>} $totals */
        $totals = $this->totals;

        return [
            'id' => $this->id,
            'day' => $this->day,
            'version' => $this->version,
            'status' => $this->status,
            'expected_cash' => $this->money($this->expected_cash),
            'counted_cash' => $this->money($this->counted_cash),
            'difference' => $this->money($this->difference),
            'totals' => [
                'by_method' => self::map(array_map(fn (string $amount): ?string => $this->money($amount), $totals['by_method'] ?? [])),
                'count' => (int) ($totals['count'] ?? 0),
                'per_member' => self::map(array_map(fn (array $member): array => [
                    'name' => $member['name'],
                    'cash' => $this->money($member['cash']),
                    'count' => (int) $member['count'],
                ], $totals['per_member'] ?? [])),
            ],
            'note' => $this->note,
            'closed_by' => $this->person($this->closedBy),
            'closed_at' => $this->moment($this->closed_at),
            'reopened_by' => $this->person($this->reopenedBy),
            'reopened_at' => $this->moment($this->reopened_at),
            'reopen_reason' => $this->reopen_reason,
            'snapshot_hash' => $this->snapshot_hash,
            'summary_text' => SummaryText::for($close, app(CurrentTenant::class)->get() ?? $this->tenant, $close->getAttribute('shop_name')),
            'summary_url' => route('api.day-close.summary', ['close' => $this->id]),
        ];
    }

    /**
     * Who it was, or null for no one (no one yet, or someone who has since left the team).
     *
     * @return array{id: string, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * A map for the JSON an app reads: an empty one is `{}`, which PHP would otherwise write as `[]`.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>|stdClass
     */
    public static function map(array $map): array|stdClass
    {
        return $map === [] ? new stdClass : $map;
    }
}
