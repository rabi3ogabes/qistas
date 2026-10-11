<?php

namespace App\Help;

use App\Models\SupportReply;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Push\PushMessage;
use App\Support\InLanguage;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Help inside the app (Win Plan PP17): a person's question or problem, and the Qistas team's replies. A reply reaches the
 * person in their inbox and on their phone, in their language; the first one is timed against the promise (24 hours,
 * 4 for a report of missing data).
 */
final class SupportTickets
{
    public function __construct(private readonly CurrentTenant $current, private readonly Notifier $notifier) {}

    /**
     * @param  array{category: string, message: string, app_version?: string|null, platform?: string|null}  $data
     */
    public function open(Tenant $tenant, User $by, array $data, ?string $language = null): SupportTicket
    {
        return $this->current->use($tenant, function () use ($by, $data, $language): SupportTicket {
            $ticket = (new SupportTicket)->forceFill([
                'user_id' => $by->id,
                'category' => $data['category'],
                'message' => trim($data['message']),
                'language' => $language,
                'app_version' => $data['app_version'] ?? null,
                'platform' => $data['platform'] ?? null,
                'status' => 'open',
            ]);
            $ticket->save();

            return $ticket;
        });
    }

    /** Staff answer a ticket. The person is told; the time of the first answer is kept. */
    public function reply(SupportTicket $ticket, User $staff, string $body): SupportReply
    {
        $reply = DB::transaction(function () use ($ticket, $staff, $body): SupportReply {
            $locked = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $reply = (new SupportReply)->forceFill(['support_ticket_id' => $locked->id, 'user_id' => $staff->id, 'body' => trim($body)]);
            $reply->save();
            $locked->forceFill(['status' => 'answered', 'first_reply_at' => $locked->first_reply_at ?? now(), 'closed_at' => null])->save();

            return $reply;
        });

        $this->tell($ticket, $reply);

        return $reply;
    }

    public function close(SupportTicket $ticket): void
    {
        $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    }

    private function tell(SupportTicket $ticket, SupportReply $reply): void
    {
        $person = User::query()->find($ticket->user_id);
        $tenant = Tenant::query()->find($ticket->tenant_id);
        if ($person === null || $tenant === null) {
            return;
        }

        $this->current->use($tenant, function () use ($person, $reply): void {
            $message = InLanguage::run((string) ($person->locale ?: 'en'), fn () => new PushMessage(
                'support.reply', __('The Qistas team replied'), Str::limit($reply->body, 180), ['route' => '/help'],
            ));
            $this->notifier->notify($person, $message, []);
        });
    }
}
