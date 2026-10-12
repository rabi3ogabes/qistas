<?php

namespace App\Documents;

use App\Models\Investor;
use App\Models\Product;

/**
 * What a document was narrowed to (Win Plan RP11), in words for its header, so a page printed for one investor or one
 * product can never be read as the whole business's.
 */
final class Narrowing
{
    /** @return list<string> */
    public static function of(DocumentOptions $options): array
    {
        $lines = [];
        if ($options->investor !== null && ($investor = Investor::query()->find($options->investor)) !== null) {
            $lines[] = __('Contracts funded by :name', ['name' => $investor->name]);
        }
        if ($options->product !== null && ($product = Product::query()->find($options->product)) !== null) {
            $lines[] = __('Contracts that sold :name', ['name' => $product->name]);
        }

        return $lines;
    }
}
