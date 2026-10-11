{{-- Support (Win Plan PP17): what people wrote from the app, open first and missing data at the top, each against the
     reply promise; the team answers here and the answer reaches the person's inbox and phone. --}}
@php
    use App\Help\SupportSettings;

    $category = [
        'question' => __('Question'), 'problem' => __('Something is wrong'), 'missing_data' => __('Missing data'),
        'billing' => __('Plan and payment'), 'idea' => __('Idea'),
    ];
    $platform = ['android' => 'Android', 'ios' => 'iPhone', 'web' => __('Web')];
    $when = fn ($moment) => $moment?->translatedFormat('j M Y, H:i');
@endphp
<x-layouts.admin :title="__('Support')" section="support">
    <x-page-head :title="__('Support')">
        <x-slot:subtitle>{{ __('What people wrote from the app. First reply within :hours hours, within :urgent for missing data.', ['hours' => \App\Models\SupportTicket::REPLY_HOURS, 'urgent' => \App\Models\SupportTicket::MISSING_DATA_HOURS]) }}</x-slot:subtitle>
    </x-page-head>

    @foreach ([['open', __('Waiting for a reply'), $open], ['answered', __('Answered'), $answered], ['closed', __('Closed recently'), $closed]] as [$key, $heading, $tickets])
        @continue($key !== 'open' && $tickets->isEmpty())
        <section class="card help-queue" aria-labelledby="{{ $key }}-title" @if (! $loop->first) style="margin-top:1rem" @endif>
            <div class="card-head">
                <h2 id="{{ $key }}-title">{{ $heading }}</h2>
                @if ($tickets->isNotEmpty())<span class="badge {{ $key === 'open' ? 'badge-warn' : '' }}">{{ $tickets->count() }}</span>@endif
            </div>
            @if ($tickets->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon name="check" :size="24" /></span>
                    <h3>{{ __('Nobody is waiting') }}</h3>
                    <p>{{ __('A question appears here as soon as someone sends it from the app.') }}</p>
                </div>
            @else
                <ul class="help-list">
                    @foreach ($tickets as $ticket)
                        @php
                            $late = $ticket->status === 'open' && $ticket->replyDue()->isPast();
                            $waited = $ticket->created_at->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
                        @endphp
                        <li class="help-item">
                            <div class="help-head">
                                <span class="badge {{ $ticket->category === 'missing_data' ? 'badge-bad' : 'badge-pro' }}">{{ $category[$ticket->category] ?? $ticket->category }}</span>
                                <a class="cell-link" href="{{ route('admin.businesses.show', $ticket->tenant_id) }}">{{ $ticket->tenant?->name }}</a>
                                <span class="cell-sub" dir="ltr">{{ $ticket->tenant ? SupportSettings::code($ticket->tenant) : '' }}</span>
                                @if ($ticket->status === 'open')
                                    <span @class(['help-wait', 'is-late' => $late])>{{ $late ? __('Late: waiting :time', ['time' => $waited]) : __('Waiting :time', ['time' => $waited]) }}</span>
                                @endif
                            </div>
                            <p class="help-message" dir="auto">{{ $ticket->message }}</p>
                            <p class="cell-sub">
                                {{ $ticket->user?->name ?? __('Someone no longer on the team') }}@if ($ticket->user), <span dir="ltr">{{ $ticket->user->email }}</span>@endif
                                · {{ $platform[$ticket->platform] ?? '' }} {{ $ticket->app_version }} · {{ $when($ticket->created_at) }}
                            </p>
                            @foreach ($ticket->replies as $reply)
                                <div class="help-reply">
                                    <p dir="auto">{{ $reply->body }}</p>
                                    <p class="cell-sub">{{ $reply->author?->name ?? __('The Qistas team') }}, {{ $when($reply->created_at) }}</p>
                                </div>
                            @endforeach
                            @if ($ticket->status !== 'closed')
                                <form method="POST" action="{{ route('admin.support.reply', $ticket) }}" class="form stack help-answer">
                                    @csrf
                                    <x-field type="textarea" name="body" :id="'reply-'.$ticket->id" :label="__('Reply')" rows="3" maxlength="5000" dir="auto"
                                        :hint="__('Write in the language they used. It reaches them in the app and on their phone.')" />
                                    <div class="help-actions">
                                        <button class="btn btn-gold btn-sm" type="submit">{{ __('Send the reply') }}</button>
                                        <button class="btn btn-ghost btn-sm" type="submit" formaction="{{ route('admin.support.close', $ticket) }}" formnovalidate>{{ __('Close') }}</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    <section class="card card-pad stack" aria-labelledby="whatsapp-title" style="margin-top:1rem">
        <h2 id="whatsapp-title" class="card-title">{{ __('Message us on WhatsApp') }}</h2>
        <p class="field-hint">{{ __('The app’s Help screen opens a WhatsApp chat with this number, with the workspace’s support code and the app version (never a password). Without a number, people write in the app instead.') }}</p>
        <form method="POST" action="{{ route('admin.support.settings') }}" class="form stack" novalidate>
            @csrf
            @method('PUT')
            <fieldset class="stack" @disabled(! $canSet) style="border:0;margin:0;padding:0">
                <x-field name="whatsapp" type="tel" :label="__('Support WhatsApp number')" :value="$whatsapp ? '+'.$whatsapp : ''" dir="ltr" autocomplete="off" placeholder="+966 50 123 4567" />
            </fieldset>
            @if ($canSet)
                <div><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
            @else
                <p class="field-hint">{{ __('Only a super admin can change these.') }}</p>
            @endif
        </form>
    </section>
</x-layouts.admin>
