<?php

namespace App\Http\Resources;

use App\Domain\PayPattern\PayPattern;
use App\Models\CustomerPayPattern;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerPayPattern
 *
 * The day of the month a customer usually pays on, as the web page and the app show it next to them (daily-habit wave A,
 * H6). The figures are for the apps to compute with: `day` (1 to 28, where 28 also stands for the 29th to the 31st) to
 * suggest dates, `end_of_month` to say so, `spread`, `months` and `within` to judge it. `label` and `basis` are the
 * words, written here in the language of the request and shown as they are sent; the numbers sit in them as
 * placeholders, so no wording depends on a count.
 */
class PayPatternResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $endOfMonth = $this->day === PayPattern::END_OF_MONTH;

        return [
            'day' => $this->day,
            'spread' => $this->spread,
            'months' => $this->months,
            'within' => $this->within,
            'confidence' => $this->confidence,
            'end_of_month' => $endOfMonth,
            'label' => $endOfMonth
                ? __('Usually pays at the end of the month')
                : __('Usually pays around day :day of the month', ['day' => $this->day]),
            'basis' => __('Seen in :percent% of recent months', ['percent' => $this->within]),
        ];
    }
}
