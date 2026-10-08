<?php

namespace App\Entitlements;

/**
 * What one workspace gets for one feature right now, and the reason when it is "no". The two kinds of "no" are
 * different on purpose: a plan-locked feature is shown with a way to upgrade; a platform-off feature is not shown at
 * all, because no plan would fix it.
 */
enum FeatureStatus: string
{
    case On = 'on';
    case PlanLocked = 'plan_locked';
    case PlatformOff = 'platform_off';
}
