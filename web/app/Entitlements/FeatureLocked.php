<?php

namespace App\Entitlements;

/** The plan does not include the feature at all. */
final class FeatureLocked extends EntitlementException
{
    public function errorCode(): string
    {
        return 'feature_locked';
    }

    protected function describe(): string
    {
        return __(':feature is not included in your plan.', ['feature' => $this->feature->label()]);
    }
}
