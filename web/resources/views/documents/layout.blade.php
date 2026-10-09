{{--
    The frame of every PDF Qistas makes: the brand's header and footer on every page, the right direction and font for
    the language, and a slot for a workspace's own branding ($branding: logo path and accent; Qistas by default).
    A document view extends this and fills `content` (and, if it wants something in the header, `meta`).
    mPDF reads a small part of CSS: tables and simple boxes, not flex or grid.
--}}
@php
    $font = $rtl ? 'plexarabic' : 'geist';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<!doctype html>
<html lang="{{ $language }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $title ?? 'Qistas' }}</title>
<style>
    body { font-family: {{ $font }}; font-size: 10.5pt; color: {{ $branding['ink'] }}; line-height: 1.5; }
    h1 { font-size: 20pt; font-weight: bold; margin: 0 0 4pt 0; text-align: {{ $start }}; }
    .muted { color: #4F5B76; }
    .head { width: 100%; border-bottom: 1.4pt solid {{ $branding['accent'] }}; }
    .word { font-size: 17pt; font-weight: bold; color: {{ $branding['ink'] }}; }
    .foot { width: 100%; border-top: 0.6pt solid #E3DCCB; font-size: 8pt; color: #4F5B76; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 10pt; }
    table.grid th { text-align: {{ $start }}; font-size: 8.5pt; color: #4F5B76; border-bottom: 0.8pt solid {{ $branding['ink'] }}; padding: 4pt 3pt; }
    table.grid td { padding: 5pt 3pt; border-bottom: 0.4pt solid #E3DCCB; }
    table.grid th.num, table.grid td.num { text-align: {{ $end }}; }
    .qr { margin-top: 18pt; text-align: {{ $start }}; font-size: 8.5pt; }
</style>
</head>
<body>
<htmlpageheader name="qistas-header">
    <table class="head"><tr>
        <td style="text-align: {{ $start }}">
            @if (! empty($branding['logo']))
                <img src="{{ $branding['logo'] }}" style="height: 26pt">
            @else
                <span class="word">qistas</span>
            @endif
        </td>
        <td style="text-align: {{ $end }}" class="muted">@yield('meta')</td>
    </tr></table>
</htmlpageheader>
<htmlpagefooter name="qistas-footer">
    <table class="foot"><tr>
        <td style="text-align: {{ $start }}">Qistas</td>
        <td style="text-align: {{ $end }}">{PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>
<sethtmlpageheader name="qistas-header" value="on" show-this-page="1" />
<sethtmlpagefooter name="qistas-footer" value="on" />

@yield('content')
</body>
</html>
