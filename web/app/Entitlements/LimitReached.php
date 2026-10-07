<?php

namespace App\Entitlements;

/** The plan includes the feature, but the workspace has used all of it. */
final class LimitReached extends EntitlementException
{
    public function errorCode(): string
    {
        return 'limit_reached';
    }

    protected function describe(): string
    {
        return __('You have reached the limit of :limit :unit on your plan.', [
            'limit' => $this->limit,
            'unit' => $this->feature->unit(),
        ]);
    }
}
