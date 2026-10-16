<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureUnavailable;
use Illuminate\Support\Facades\Exceptions;

/*
| "Not on your plan" and "switched off" are answers, not crashes: they are shown to the person and never written to the
| error log, where they would bury real failures.
*/

it('does not log a page that is switched off as an error', function () {
    Exceptions::fake();
    switchOff(Feature::InstalmentAlerts);
    [$owner] = owner();

    $this->actingAs($owner)->get('/app/reminders')->assertForbidden();

    Exceptions::assertNotReported(FeatureUnavailable::class);
});
