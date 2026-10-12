<x-mail::message>
# {{ $notice->title }}

{{ $notice->body }}

<x-mail::button :url="$link">
{{ __('Open Qistas') }}
</x-mail::button>

{{ __('You chose to receive this by e-mail. Change it in Settings, then Alerts.') }}
</x-mail::message>
