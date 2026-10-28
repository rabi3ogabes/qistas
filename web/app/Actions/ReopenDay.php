<?php

namespace App\Actions;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\DayClose;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reopens a closed day (daily-habit wave A, H4, decision D10): an owner or a manager, with a reason, so a change the
 * day needs can be made and the day closed again as its next version. The close itself stays as it was signed: only its
 * status moves, and who reopened it, when and why are written beside it. Reopening is one way: a close that was
 * reopened is never closed again; a new close is written instead.
 */
final class ReopenDay
{
    /** A reason shorter than this is not a reason. */
    public const MIN_REASON = 5;

    /** The longest a reason may be: it is kept as the reopening's one line of why. */
    public const MAX_REASON = 500;

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @throws FeatureUnavailable|FeatureLocked when the workspace does not have the end-of-day close
     * @throws AuthorizationException when [$by] is not an owner or a manager of the close's workspace
     * @throws ValidationException on `reason` (shorter than five characters, or longer than five hundred) or `day` (the close is not the day's standing one)
     */
    public function handle(DayClose $close, string $reason, User $by): DayClose
    {
        $tenant = $close->tenant;

        return $this->current->use($tenant, function () use ($tenant, $close, $reason, $by): DayClose {
            Entitlements::for($tenant)->assertEnabled(Feature::DayClose);
            Gate::forUser($by)->authorize('reopen', $close);

            $reason = trim($reason);
            if (mb_strlen($reason) < self::MIN_REASON) {
                throw ValidationException::withMessages(['reason' => __('Give a reason of at least :min characters.', ['min' => self::MIN_REASON])]);
            }
            if (mb_strlen($reason) > self::MAX_REASON) {
                throw ValidationException::withMessages(['reason' => __('Keep the reason to :max characters or fewer.', ['max' => self::MAX_REASON])]);
            }

            return DB::transaction(function () use ($tenant, $close, $reason, $by): DayClose {
                // The lock a close of the same day takes: a reopening and a close take turns.
                $latest = DayClose::query()->where('day', $close->day)->orderByDesc('version')->lockForUpdate()->first();
                if ($latest === null || $latest->id !== $close->id || $latest->status !== 'closed') {
                    throw ValidationException::withMessages(['day' => __('Only a day that is closed can be reopened.')]);
                }

                $latest->forceFill(['status' => 'reopened', 'reopened_by_user_id' => $by->id, 'reopened_at' => now(), 'reopen_reason' => $reason])->save();
                // The reason stays on the close: free text may name a customer, and the audit trail keeps none.
                Audit::record('day_close.reopened', $latest, ['day' => $latest->day, 'version' => $latest->version], $tenant->id, $by->id);

                return $latest;
            });
        });
    }
}
