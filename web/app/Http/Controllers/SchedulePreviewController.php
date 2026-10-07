<?php

namespace App\Http\Controllers;

use App\Domain\Schedule\InvalidScheduleException;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Http\Requests\SchedulePreviewRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Shows what an instalment plan would look like, using the very engine that writes real contracts. */
final class SchedulePreviewController
{
    public function __invoke(SchedulePreviewRequest $request, ScheduleGenerator $generator): JsonResponse
    {
        $input = $request->validated();

        try {
            $schedule = $generator->generate(ScheduleRequest::fromArray([
                'principal' => $input['principal'],
                'down_payment' => $input['down_payment'] ?? '0',
                'markup_type' => $input['markup_type'] ?? 'none',
                'markup_value' => $input['markup_value'] ?? '0',
                'count' => $input['count'],
                'frequency' => $input['frequency'],
                'first_due_date' => $input['first_due_date'] ?? now()->addMonthNoOverflow()->format('Y-m-d'),
            ]));
        } catch (InvalidScheduleException $e) {
            throw ValidationException::withMessages(['principal' => $e->getMessage()]);
        }

        return response()->json($schedule->toArray());
    }
}
