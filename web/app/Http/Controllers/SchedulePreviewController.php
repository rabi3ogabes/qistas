<?php

namespace App\Http\Controllers;

use App\Domain\Schedule\Discount;
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
        // A discount at sale comes off the price before the down payment (Win Plan PP6).
        $discount = Discount::amount($input['principal'], $input['discount_type'] ?? 'none', $input['discount_value'] ?? null);

        try {
            $schedule = $generator->generate(ScheduleRequest::fromArray([
                'principal' => Discount::net($input['principal'], $discount),
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

        return response()->json([...$schedule->toArray(), 'discount' => $discount]);
    }
}
