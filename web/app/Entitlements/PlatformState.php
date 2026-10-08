<?php

namespace App\Entitlements;

/**
 * The platform admin's switch for one feature, ahead of any plan:
 * `off` nobody has it, `beta` only workspaces the admin has let in, `on` everyone the plan allows.
 */
enum PlatformState: string
{
    case Off = 'off';
    case Beta = 'beta';
    case On = 'on';
}
