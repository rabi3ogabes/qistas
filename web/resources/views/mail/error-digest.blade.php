{{--
    The daily digest of the error center (App\Mail\ErrorDigestMail). Needs $language, $dir, $brand, $listLink and $sections:
    the parts that have something in them, each with a heading, a hint, its issues (source, title, count, link) and how
    many more there were. Titles are printed as text and nothing else about a failure is available here.
--}}
@extends('mail.error-frame')

@section('heading', __('Error summary of the last 24 hours'))

@section('body')
<h1 dir="{{ $dir }}" style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:32px;font-weight:600;color:#0B1F44;">{{ __('Error summary of the last 24 hours') }}</h1>
@foreach ($sections as $section)
<h2 dir="{{ $dir }}" style="margin:28px 0 2px;font-size:17px;line-height:26px;font-weight:600;color:#0B1F44;">{{ $section['heading'] }}</h2>
<p dir="{{ $dir }}" style="margin:0 0 10px;font-size:13px;line-height:20px;color:#5B6070;">{{ $section['hint'] }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #EDE6D6;">
@foreach ($section['issues'] as $issue)
<tr>
<td dir="{{ $dir }}" valign="top" style="padding:14px;border-bottom:1px solid #EDE6D6;">
<div dir="{{ $dir }}" style="font-size:12px;line-height:18px;font-weight:600;color:#5B6070;">{{ $issue['source'] }}</div>
<a href="{{ $issue['link'] }}" dir="auto" style="font-weight:600;color:#0B1F44;text-decoration:underline;word-break:break-word;">{{ $issue['title'] }}</a>
<div dir="{{ $dir }}" style="font-size:13px;line-height:20px;color:#5B6070;">{{ __('Times seen') }}: {{ $issue['count'] }}</div>
</td>
</tr>
@endforeach
@if ($section['more'] > 0)
<tr>
<td dir="{{ $dir }}" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-size:13px;line-height:20px;color:#5B6070;">{{ __('And :count more.', ['count' => $section['more']]) }}</td>
</tr>
@endif
</table>
@endforeach
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
<tr>
<td style="border-radius:6px;background-color:#0B1F44;"><a href="{{ $listLink }}" style="display:inline-block;padding:13px 26px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;font-weight:600;line-height:20px;color:#FFFFFF;text-decoration:none;border-radius:6px;">{{ __('See the errors') }}</a></td>
</tr>
</table>
@endsection

@section('footer', __('You receive this summary once a day, and only when something is new, came back or is growing.'))
