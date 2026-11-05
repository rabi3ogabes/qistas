{{-- The plain-text part of the instant e-mail: the same words as error-alert.blade.php, for programs that show no HTML. Plain text, so nothing here is HTML-escaped. --}}{!! __('A new error has appeared') !!}

{!! __('Qistas has recorded an error it had not seen before.') !!}

{!! __('Source') !!}: {!! $source !!}
{!! __('What happened') !!}: {!! $title !!}
{!! __('Times seen') !!}: {!! $count !!}
@if ($version !== null && $version !== '')
{!! __('Version :version', ['version' => $version]) !!}
@endif

{!! __('Open the error') !!}: {!! $link !!}

--
{!! __('You receive this because you are a super admin. At most :hour of these are sent an hour and :day a day; the others wait for the daily summary.', ['hour' => $hourLimit, 'day' => $dayLimit]) !!}
