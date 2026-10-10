<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The free plan grows (Win Plan 6.4): 20 customers instead of 5, no limit on running contracts instead of 5, five
 * documents a month instead of three. Only a stored setting that still holds the old built-in number is raised;
 * a number the admin chose stays exactly as it is.
 */
return new class extends Migration
{
    /** @var array<string, array{0: int, 1: int|null}> feature key => [old free value, new free value] */
    private const CHANGES = [
        'customers' => [5, 20],
        'active_contracts' => [5, null],
        'pdf_statements' => [3, 5],
    ];

    public function up(): void
    {
        $this->swap(fn (array $change) => [$change[0], $change[1]]);
    }

    public function down(): void
    {
        $this->swap(fn (array $change) => [$change[1], $change[0]]);
    }

    /** @param  Closure(array{0: int, 1: int|null}): array{0: int|null, 1: int|null}  $direction  [from, to] */
    private function swap(Closure $direction): void
    {
        $free = DB::table('plans')->where('key', 'free')->value('id');
        if ($free === null) {
            return;
        }

        foreach (self::CHANGES as $feature => $change) {
            [$from, $to] = $direction($change);
            $rows = DB::table('plan_features')->where('plan_id', $free)->where('feature_key', $feature);
            $rows = $from === null ? $rows->whereNull('limit_value') : $rows->where('limit_value', $from);
            $rows->update(['limit_value' => $to, 'updated_at' => now()]);
        }
    }
};
