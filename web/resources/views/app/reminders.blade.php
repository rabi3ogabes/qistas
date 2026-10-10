{{-- Remind everyone (Win Plan PP9): each customer a tap away from WhatsApp, with the message ready, and the business's own wording. --}}
@php
    use App\Reminders\ReminderTemplates;
    use App\Support\Format;
    use App\Support\Locale;
    use Illuminate\Support\Carbon;

    $tabs = ['due' => __('Due today'), 'late' => __('Late')];
    $keys = ['reminder_due' => __('Before it is late'), 'reminder_late' => __('Once it is late')];
@endphp
<x-layouts.app :title="__('Remind everyone')" section="dashboard">
    <x-page-head :title="__('Remind everyone')">
        <x-slot:subtitle>{{ __('Each button opens WhatsApp with the message written. Send it, come back, and go on to the next.') }}</x-slot:subtitle>
    </x-page-head>

    <nav class="tabs" aria-label="{{ __('Choose who to remind') }}">
        @foreach ($tabs as $key => $name)
            <a class="tabs-item" href="{{ route('app.reminders.index', ['scope' => $key]) }}" @if ($key === $scope) aria-current="page" @endif>
                {{ $name }} <span class="tabs-count money">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <section class="card" aria-label="{{ $tabs[$scope] }}">
        @if ($rows === [])
            <div class="empty">
                <span class="empty-icon"><x-icon name="calendar" :size="24" /></span>
                <h3>{{ $scope === 'late' ? __('Nobody is late') : __('Nothing is due today.') }}</h3>
                <p>{{ __('When there is someone to remind, they will be here with the message ready.') }}</p>
            </div>
        @else
            <ol class="remind-list">
                @foreach ($rows as $row)
                    <li class="remind-row">
                        <div class="remind-who">
                            <a class="cell-link" href="{{ url('/app/contracts/'.$row['contract_id']) }}">{{ $row['customer_name'] }}</a>
                            <span class="cell-sub"><span dir="ltr">{{ $row['contract_reference'] }}</span>, {{ Carbon::parse($row['due_date'])->translatedFormat('j F Y') }}</span>
                            @isset($row['days_late'])<span class="badge badge-bad">{{ __('Days late: :count', ['count' => $row['days_late']]) }}</span>@endisset
                        </div>
                        <span class="money remind-amount">{{ Format::money($row['amount_due'], $currency) }}</span>
                        @if ($row['whatsapp'])
                            <a class="btn btn-quiet btn-sm remind-send" href="https://wa.me/{{ $row['whatsapp'] }}?text={{ rawurlencode($row['message']) }}" target="_blank" rel="noopener noreferrer">
                                <x-icon name="message" :size="16" /> {{ __('WhatsApp') }}
                            </a>
                        @else
                            <span class="cell-sub remind-send">{{ __('No phone number') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($canEdit)
        <section class="card card-pad stack" aria-labelledby="wording-title">
            <div>
                <h2 id="wording-title" class="card-title">{{ __('Your wording') }}</h2>
                <p class="muted">{{ __('Write the reminders the way you speak to your customers. These words are filled in for you: :placeholders', ['placeholders' => implode(' ', ReminderTemplates::PLACEHOLDERS)]) }}</p>
            </div>
            <nav class="tabs tabs-compact" aria-label="{{ __('Language of the wording') }}">
                @foreach (Locale::options() as $code => $name)
                    <a class="tabs-item" href="{{ route('app.reminders.index', ['scope' => $scope, 'language' => $code]) }}#wording-title" lang="{{ $code }}" @if ($code === $language) aria-current="page" @endif>{{ $name }}</a>
                @endforeach
            </nav>
            @foreach ($keys as $key => $label)
                @php($template = $wording[$key])
                <form method="POST" action="{{ route('app.reminders.templates.update', $key) }}" class="form stack wording-form">
                    @csrf @method('PUT')
                    <input type="hidden" name="language" value="{{ $language }}">
                    <div class="field">
                        <label for="wording-{{ $key }}">{{ $label }} @if ($template['custom'])<span class="badge">{{ __('Your own words') }}</span>@endif</label>
                        <textarea id="wording-{{ $key }}" name="body" rows="3" maxlength="{{ ReminderTemplates::MAX_LENGTH }}" lang="{{ $language }}" dir="{{ in_array($language, config('qistas.rtl_locales'), true) ? 'rtl' : 'ltr' }}">{{ $template['body'] }}</textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-sm" type="submit">{{ __('Save') }}</button>
                        @if ($template['custom'])
                            <button class="btn btn-ghost btn-sm" type="submit" name="reset" value="1">{{ __('Use the default') }}</button>
                        @endif
                    </div>
                </form>
            @endforeach
        </section>
    @endif
</x-layouts.app>
