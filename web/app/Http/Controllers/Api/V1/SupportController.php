<?php

namespace App\Http\Controllers\Api\V1;

use App\Help\SupportSettings;
use App\Help\SupportTickets;
use App\Models\SupportReply;
use App\Models\SupportTicket;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Help inside the app (Win Plan PP17): how to reach the team, the person's own questions with the replies, and a new
 * question. Only what is listed is read from a request: a password or a token sent along is never kept.
 */
final class SupportController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function info(SupportSettings $settings): JsonResponse
    {
        return response()->json(['data' => [
            'whatsapp' => $settings->whatsapp(),
            'code' => SupportSettings::code($this->current->get() ?? abort(403)),
            'reply_hours' => SupportTicket::REPLY_HOURS,
            'missing_data_hours' => SupportTicket::MISSING_DATA_HOURS,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()->with('replies')->where('user_id', $request->user()->id)->latest()->limit(50)->get();

        return response()->json(['data' => $tickets->map(fn (SupportTicket $t) => $this->present($t))->values()]);
    }

    public function store(Request $request, SupportTickets $tickets): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'message' => ['required', 'string', 'max:5000'],
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+([+.-][0-9A-Za-z.]+)?$/'],
            'platform' => ['nullable', Rule::in(SupportTicket::PLATFORMS)],
        ]);

        $ticket = $tickets->open($this->current->get() ?? abort(403), $request->user(), $data, app()->getLocale());

        return response()->json(['data' => $this->present($ticket->load('replies'))], 201);
    }

    /** @return array<string, mixed> */
    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'category' => $ticket->category,
            'message' => $ticket->message,
            'status' => $ticket->status,
            'app_version' => $ticket->app_version,
            'created_at' => $ticket->created_at->toIso8601String(),
            'first_reply_at' => $ticket->first_reply_at?->toIso8601String(),
            'replies' => $ticket->replies->map(fn (SupportReply $r) => ['body' => $r->body, 'created_at' => $r->created_at->toIso8601String()])->values()->all(),
        ];
    }
}
