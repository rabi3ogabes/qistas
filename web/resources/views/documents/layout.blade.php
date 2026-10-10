{{--
    The frame of every PDF Qistas makes: a header and footer on every page, the right direction and font for the language,
    and the shop's own identity when it has one ($branding: its logo when its plan includes branding, its name, and whether
    the footer says the document was made with Qistas or carries the shop's own line).
    A document view extends this and fills `content` (and `meta`, the right-hand side of the header).
    mPDF reads a small part of CSS: tables and simple boxes, not flex or grid.
--}}
@php
    $font = $rtl ? 'plexarabic' : 'geist';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $size = $fontSize ?? 10.5;
    $compact = $compact ?? false;
    $details = isset($profile) && ! $compact ? array_filter([
        $profile->fields['phone'] ?? null,
        $profile->fields['address'] ?? null,
        ($profile->fields['cr_number'] ?? null) ? __('CR :number', ['number' => $profile->fields['cr_number']]) : null,
        ($profile->fields['vat_number'] ?? null) ? __('VAT :number', ['number' => $profile->fields['vat_number']]) : null,
    ]) : [];
@endphp
<!doctype html>
<html lang="{{ $language }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $title ?? 'Qistas' }}</title>
<style>
    body { font-family: {{ $font }}; font-size: {{ $size }}pt; color: {{ $branding['ink'] }}; line-height: 1.45; }
    h1 { font-size: {{ round($size * 1.8, 1) }}pt; font-weight: bold; margin: 0 0 2pt 0; text-align: {{ $start }}; }
    h2 { font-size: {{ round($size * 1.15, 1) }}pt; font-weight: bold; margin: 12pt 0 0 0; text-align: {{ $start }}; }
    p { margin: 0; }
    .muted { color: #56607A; }
    .small { font-size: {{ round($size * 0.82, 1) }}pt; }
    .head { width: 100%; border-bottom: 1.2pt solid {{ $branding['accent'] }}; }
    .word { font-size: {{ $compact ? 13 : 16 }}pt; font-weight: bold; color: {{ $branding['ink'] }}; }
    .foot { width: 100%; border-top: 0.5pt solid #E3DCCB; font-size: 7.5pt; color: #56607A; }
    table.facts { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    table.facts td { padding: 2pt 0; vertical-align: top; }
    table.facts td.label { color: #56607A; width: 34%; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    table.grid th { text-align: {{ $start }}; font-size: {{ round($size * 0.8, 1) }}pt; font-weight: normal; color: #56607A; border-bottom: 0.8pt solid {{ $branding['ink'] }}; padding: 3pt 3pt; }
    table.grid td { padding: 3.2pt 3pt; border-bottom: 0.4pt solid #E9E3D6; vertical-align: top; }
    table.grid .amount { width: 16%; }
    table.grid th.num, table.grid td.num { text-align: {{ $end }}; white-space: nowrap; }
    table.grid tr.sum td { border-top: 0.8pt solid {{ $branding['ink'] }}; border-bottom: none; font-weight: bold; }
    table.grid tr.opening td { color: #56607A; }
    .late { color: #A13A28; }
    .owed { width: 100%; border-collapse: collapse; margin-top: 10pt; background-color: #F7F2E7; }
    .owed td { padding: 7pt 11pt; border-top: 1.2pt solid {{ $branding['accent'] }}; }
    .owed .figure { font-size: {{ round($size * 1.6, 1) }}pt; font-weight: bold; text-align: {{ $end }}; }
    .stamp { color: #A13A28; border: 1.2pt solid #A13A28; padding: 3pt 8pt; font-weight: bold; }
    table.closing { width: 100%; border-collapse: collapse; margin-top: 14pt; page-break-inside: avoid; }
    table.closing td { vertical-align: bottom; }
    .qr { margin-top: 18pt; text-align: {{ $start }}; font-size: 8.5pt; }
</style>
</head>
<body>
<htmlpageheader name="qistas-header">
    <table class="head"><tr>
        <td style="text-align: {{ $start }}; padding-bottom: 5pt; vertical-align: bottom">
            @if (! empty($branding['logo']))
                <img src="{{ $branding['logo'] }}" style="height: {{ $compact ? 20 : 30 }}pt">
            @elseif (! empty($branding['name']))
                <span class="word">{{ $branding['name'] }}</span>
            @else
                <span class="word">qistas</span>
            @endif
            @if ($details !== [])
                <div class="muted small">{{ implode('  |  ', $details) }}</div>
            @endif
        </td>
        <td style="text-align: {{ $end }}; padding-bottom: 5pt; vertical-align: bottom" class="muted">@yield('meta')</td>
    </tr></table>
</htmlpageheader>
<htmlpagefooter name="qistas-footer">
    <table class="foot"><tr>
        <td style="text-align: {{ $start }}">{{ ($branding['qistas'] ?? true) ? __('Made with Qistas') : ($branding['footer'] ?? '') }}</td>
        <td style="text-align: {{ $end }}" dir="ltr">{PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>
<sethtmlpageheader name="qistas-header" value="on" show-this-page="1" />
<sethtmlpagefooter name="qistas-footer" value="on" />

@yield('content')
</body>
</html>
