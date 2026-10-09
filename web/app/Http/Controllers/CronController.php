<?php

namespace App\Http\Controllers;

use App\Cron\CronRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The door a scheduler knocks on (GET or POST /internal/cron, with `Authorization: Bearer <CRON_SECRET>`). It does one
 * bounded slice of scheduled work per call. With no secret configured it is closed (503); with a wrong or missing one
 * it says 401 and does nothing; while another call is still running it says 409.
 */
final class CronController
{
    public function __invoke(Request $request, CronRunner $runner): JsonResponse
    {
        $secret = (string) config('qistas.cron.secret');

        if ($secret === '') {
            return response()->json(['error' => 'not_configured'], 503);
        }

        // Compare hashes, so neither the secret's contents nor its length can be learned from how long this takes.
        if (! hash_equals(hash('sha256', $secret), hash('sha256', (string) $request->bearerToken()))) {
            return response()->json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $lock = Cache::lock('cron:run', 60);

        if (! $lock->get()) {
            return response()->json(['error' => 'busy'], 409);
        }

        try {
            $run = $runner->run();
        } finally {
            $lock->release();
        }

        // A slice that failed answers 500, so the caller (the GitHub workflow) shows red and the owner hears of it.
        $failed = $run->outcome !== 'ok';

        return response()->json(array_filter([
            'error' => $failed ? 'run_failed' : null,
            'data' => [
                'outcome' => $run->outcome,
                'jobs' => $run->jobs,
                'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
        ]), $failed ? 500 : 200);
    }
}
