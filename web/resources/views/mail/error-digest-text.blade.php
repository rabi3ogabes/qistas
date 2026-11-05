{{-- The plain-text part of the daily digest: the same words as error-digest.blade.php, for programs that show no HTML. Plain text, so nothing here is HTML-escaped. --}}{!! __('Error summary of the last 24 hours') !!}
@foreach ($sections as $section)

{!! $section['heading'] !!}
{!! $section['hint'] !!}
@foreach ($section['issues'] as $issue)

- [{!! $issue['source'] !!}{!! $issue['note'] !== null ? ' · '.$issue['note'] : '' !!}] {!! $issue['title'] !!}
  {!! __('Times seen') !!}: {!! $issue['count'] !!}
  {!! $issue['link'] !!}
@endforeach
@if ($section['more'] > 0)

{!! __('And :count more.', ['count' => $section['more']]) !!}
@endif
@endforeach

{!! __('See the errors') !!}: {!! $listLink !!}

--
{!! __('You receive this summary once a day, and only when something is new, came back or is growing.') !!}
