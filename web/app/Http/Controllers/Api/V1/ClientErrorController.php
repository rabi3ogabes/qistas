<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Errors\ClientErrorIntake;
use App\Errors\ClientSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the phone app tells us about its crashes (design section 9). It works with an app token or without one (a crash on
 * the sign-in screen matters), so the route is outside the sign-in group and a token is only looked at: a missing, bad or
 * expired one, or one made for something else than the phone app, is nobody, never a refusal. Anyone can post here, so it
 * only checks the shape, hands the report to the intake and answers 204; the limits are GuardClientReport (which also turns
 * away anything a web browser sent) and the `client-errors-api` throttle, and a refusal is a status with no words. Unknown
 * fields are not read, and the kind and the source are always `crash` and `app`.
 */
final class ClientErrorController
{
    /** What the app sends, field by field; `os_version` is read and not kept. */
    private const RULES = [
        'class' => ['required', 'string', 'max:160'],
        'message' => ['nullable', 'string', 'max:500'],
        'trace' => ['nullable', 'string', 'max:8000'],
        'version' => ['nullable', 'string', 'max:16'],
        'os' => ['required', 'string', 'in:android,ios'],
        'os_version' => ['nullable', 'string', 'max:24'],
        'device' => ['nullable', 'string', 'in:phone,tablet'],
    ];

    public function store(Request $request, ClientErrorIntake $intake): Response
    {
        $report = Validator::make($request->json()->all(), self::RULES);

        if ($report->fails()) {
            return response('', 422);
        }

        $data = $report->validated();
        $id = ClientSender::person($request)?->getAuthIdentifier();

        // The new issues it may open count against the person, not the token: every sign-in makes a new token.
        $intake->accept(
            'app',
            'crash',
            $data,
            is_int($id) || is_string($id) ? (string) $id : null,
            (string) $data['os'],
            ClientSender::quotaKey($request),
        );

        return response()->noContent();
    }
}
