<?php

namespace App\Http\Controllers\Admin;

use App\Help\SupportSettings;
use App\Help\SupportTickets;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The support queue (Win Plan PP17): open questions first, reports of missing data at the top, each with how long it has
 * waited against the promise; replies go to the person's inbox and phone. Platform staff answer; a super admin sets the
 * support WhatsApp number. Tickets come from every workspace, so they are read without the workspace scope here.
 */
final class SupportController
{
    public function index(SupportSettings $settings): View
    {
        $tickets = SupportTicket::withoutGlobalScopes()->with(['tenant', 'user', 'replies.author'])
            ->orderByRaw("case when status = 'closed' then 2 when status = 'answered' then 1 else 0 end")
            ->orderByRaw("case when category = 'missing_data' then 0 else 1 end")
            ->orderBy('created_at')
            ->limit(100)->get();

        return view('admin.support.index', [
            'open' => $tickets->where('status', 'open')->values(),
            'answered' => $tickets->where('status', 'answered')->values(),
            'closed' => $tickets->where('status', 'closed')->sortByDesc('closed_at')->take(20)->values(),
            'whatsapp' => $settings->whatsapp(),
            'canSet' => Gate::allows('manage-plans'),
        ]);
    }

    public function reply(Request $request, string $ticket, SupportTickets $tickets): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => __('Write the reply first.')]);

        $tickets->reply($this->find($ticket), $request->user(), $data['body']);

        return back()->with('status', __('Reply sent. The person sees it in the app.'));
    }

    public function close(string $ticket, SupportTickets $tickets): RedirectResponse
    {
        $tickets->close($this->find($ticket));

        return back()->with('status', __('Ticket closed.'));
    }

    public function settings(Request $request, SupportSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate(['whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[\d\s().-]{8,20}$/']], [
            'whatsapp.regex' => __('Write the number in international form, for example +966 50 123 4567.'),
        ]);

        $settings->setWhatsapp($data['whatsapp'] ?? null, $request->user());

        return back()->with('status', __('Support settings saved.'));
    }

    private function find(string $id): SupportTicket
    {
        return SupportTicket::withoutGlobalScopes()->whereKey($id)->firstOrFail();
    }
}
