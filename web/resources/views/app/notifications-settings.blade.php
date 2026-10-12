{{-- A person's own notices (Sprint 3 Task 1b): each kind on or off, and where it reaches them. --}}
@php
    use App\Notifications\Catalog;

    $groups = collect($kinds)->groupBy(fn (array $kind) => $kind['group'], preserveKeys: true);
    // A channel is offered when it is set up; per kind, only where the admin allows it.
    $offered = array_keys(array_filter($available));
@endphp
<x-layouts.app :title="__('Notifications')" section="notifications">
    <x-page-head :title="__('Notifications')">
        <x-slot:subtitle>{{ __('Choose what you are told, and how. Timing (the morning summary’s hour, quiet hours) is set in the app.') }}</x-slot:subtitle>
    </x-page-head>

    @if (! $emailVerified)
        <p class="alert alert-info">{{ __('Verify your e-mail address to receive notices by e-mail.') }}</p>
    @endif

    <form method="POST" action="{{ route('app.settings.notifications.update') }}" class="stack">
        @csrf
        @method('PUT')
        @foreach ($groups as $group => $members)
            <section class="card" aria-labelledby="notify-{{ $group }}">
                <div class="card-head"><h2 id="notify-{{ $group }}">{{ Catalog::group($group) }}</h2></div>
                <ul class="notify-list" role="list">
                    @foreach ($members as $kind => $definition)
                        <li class="notify-row">
                            <label class="notify-kind">
                                <input type="checkbox" name="kinds[{{ $kind }}][enabled]" value="1" @checked($choices[$kind]['enabled'])>
                                <span>
                                    <span class="notify-title">{{ Catalog::label($kind) }}</span>
                                    <span class="cell-sub">{{ Catalog::description($kind) }}</span>
                                </span>
                            </label>
                            <div class="notify-channels" role="group" aria-label="{{ __('How :kind reaches you', ['kind' => Catalog::label($kind)]) }}">
                                @foreach (Catalog::CHANNELS as $channel)
                                    @if (in_array($channel, $offered, true) && $allowed[$kind][$channel])
                                        <label class="chip-check">
                                            <input type="checkbox" name="kinds[{{ $kind }}][channels][{{ $channel }}]" value="1" @checked($choices[$kind]['channels'][$channel] ?? false)>
                                            <span>{{ Catalog::channel($channel) }}</span>
                                        </label>
                                    @elseif ($choices[$kind]['channels'][$channel] ?? false)
                                        {{-- Kept for when the channel is back: not shown, never lost. --}}
                                        <input type="hidden" name="kinds[{{ $kind }}][channels][{{ $channel }}]" value="1">
                                    @endif
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        @unless ($available['sms'])
            <p class="field-hint">{{ __('By text message: not available yet.') }}</p>
        @endunless
        <div class="form-actions"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
    </form>
</x-layouts.app>
