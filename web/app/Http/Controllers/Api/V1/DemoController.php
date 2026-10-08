<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\DeviceSession;
use App\Http\ApiException;
use App\Sandbox\DemoAccess;
use App\Sandbox\DemoBusy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's "Try the demo": what is on offer, and a signed-in throw-away account for the one chosen. */
final class DemoController
{
    public function index(DemoAccess $demo): JsonResponse
    {
        $enabled = DemoAccess::enabled();

        return response()->json(['data' => [
            'enabled' => $enabled,
            'hours' => (int) config('qistas.demo_login.hours'),
            'personas' => $enabled ? $demo->personas() : [],
        ]]);
    }

    public function start(Request $request, string $persona, DemoAccess $demo): JsonResponse
    {
        $request->validate(['device_name' => ['nullable', 'string', 'max:100']]);
        abort_unless(DemoAccess::enabled(), 404);

        try {
            $user = $demo->start($persona);
        } catch (DemoBusy) {
            throw new ApiException('demo_busy', __('The demo is busy right now. Please try again in a few minutes.'), 503);
        }

        return DeviceSession::issue($user, $request, 201, $user->demo_expires_at);
    }
}
