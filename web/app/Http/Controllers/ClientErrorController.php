<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Errors\ClientErrorIntake;
use App\Errors\ClientSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the website's own scripts tell us about their errors (design section 9; resources/js/error-report.js sends it).
 * Anyone can post here, so it only checks the shape, hands the report to the intake and answers 204; the limits (size,
 * type, origin, rate) are GuardClientReport and the `client-errors` throttle, and a refusal is a status with no words.
 * Unknown fields are not read. The kind and the source are not the sender's to choose: they are always `script` and `site`.
 *
 * What the script sends is a class name and a path, so that is all that is taken for those two: a class is an identifier,
 * a route starts with a slash. The text of any of it is still the sender's: it is stored as text, and whatever shows it
 * (the admin's pages) must escape it and must never use the route as a link.
 */
final class ClientErrorController
{
    /** Every field is bounded by its column in the error tables (ErrorScrubber's widths), and the whole body by 8 KB. */
    private const RULES = [
        // `D`: without it `$` also matches before a closing line break, and the reporters' bodies are not trimmed.
        'class' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z_$][\w$.]*$/D'],
        'message' => ['required', 'string', 'max:500'],
        'trace' => ['nullable', 'string', 'max:6000'],
        'route' => ['nullable', 'string', 'max:160', 'regex:~^/~'],
    ];

    public function store(Request $request, ClientErrorIntake $intake): Response
    {
        $report = Validator::make($request->json()->all(), self::RULES);

        if ($report->fails()) {
            return response('', 422);
        }

        $intake->accept('site', 'script', $report->validated(), null, 'web', ClientSender::address($request));

        return response()->noContent();
    }
}
