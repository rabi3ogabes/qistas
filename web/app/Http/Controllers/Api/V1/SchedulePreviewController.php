<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schedule\InvalidScheduleException;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Http\Requests\SchedulePreviewRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** What an instalment plan would look like, from the very engine that writes real contracts. Nothing is stored. */
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
                'count' => $input['count'] ?? 0,
                'frequency' => $input['frequency'],
                'first_due_date' => $input['first_due_date'] ?? now()->addMonthNoOverflow()->format('Y-m-d'),
                'custom_schedule' => $input['custom_schedule'] ?? null,
            ]));
        } catch (InvalidScheduleException $e) {
            // The shop's own rows have their own field, so a form can show the message beside them.
            throw ValidationException::withMessages([$input['frequency'] === 'custom' ? 'custom_schedule' : 'principal' => $e->getMessage()]);
        }

        return response()->json(['data' => $schedule->toArray()]);
    }
}
