{{--
    The instant e-mail for a new server error (App\Mail\ErrorAlertMail). Needs $language, $dir, $brand, $source, $title,
    $count, $version (or null) and $link. The title is printed as text and nothing else about the failure is available here.
--}}
@extends('mail.error-frame')

@section('heading', __('A new error has appeared'))

@section('body')
<h1 dir="{{ $dir }}" style="margin:0 0 12px;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:32px;font-weight:600;color:#0B1F44;">{{ __('A new error has appeared') }}</h1>
<p dir="{{ $dir }}" style="margin:0 0 24px;color:#3D4560;">{{ __('Qistas has recorded an error it had not seen before.') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;border-top:1px solid #EDE6D6;">
<tr>
<td dir="{{ $dir }}" valign="top" width="34%" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-size:13px;color:#5B6070;">{{ __('Source') }}</td>
<td dir="{{ $dir }}" valign="top" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-weight:600;">{{ $source }}</td>
</tr>
<tr>
<td dir="{{ $dir }}" valign="top" width="34%" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-size:13px;color:#5B6070;">{{ __('What happened') }}</td>
<td dir="auto" valign="top" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-weight:600;word-break:break-word;">{{ $title }}</td>
</tr>
<tr>
<td dir="{{ $dir }}" valign="top" width="34%" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;font-size:13px;color:#5B6070;">{{ __('Times seen') }}</td>
<td dir="{{ $dir }}" valign="top" style="padding:12px 14px;border-bottom:1px solid #EDE6D6;">{{ $count }}
@if ($version !== null && $version !== '')
<div dir="{{ $dir }}" style="font-size:13px;line-height:20px;color:#5B6070;">{{ __('Version :version', ['version' => $version]) }}</div>
@endif
</td>
</tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td style="border-radius:6px;background-color:#0B1F44;"><a href="{{ $link }}" style="display:inline-block;padding:13px 26px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;font-weight:600;line-height:20px;color:#FFFFFF;text-decoration:none;border-radius:6px;">{{ __('Open the error') }}</a></td>
</tr>
</table>
@endsection

@section('footer', __('You receive this because you are a super admin. At most 3 of these are sent an hour and 10 a day; the others wait for the daily summary.'))
