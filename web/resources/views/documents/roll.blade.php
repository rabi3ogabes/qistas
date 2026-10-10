{{--
    The frame of a receipt printed on a till roll (58 or 80 mm): one narrow column, the shop at the top, no page header or
    footer (the roll is cut where the text ends). Same $branding as documents.layout.
--}}
@php
    $font = $rtl ? 'plexarabic' : 'geist';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $size = $fontSize ?? 9;
    $details = isset($profile) ? array_filter([
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
<style>
    body { font-family: {{ $font }}; font-size: {{ $size }}pt; color: #000000; line-height: 1.35; }
    h1 { font-size: {{ round($size * 1.4, 1) }}pt; font-weight: bold; margin: 6pt 0 0 0; text-align: center; }
    h2 { font-size: {{ $size }}pt; font-weight: bold; margin: 8pt 0 0 0; text-align: {{ $start }}; }
    p { margin: 0; text-align: center; }
    .muted { color: #333333; }
    .small { font-size: {{ round($size * 0.85, 1) }}pt; }
    .shop { text-align: center; border-bottom: 0.6pt dashed #000000; padding-bottom: 5pt; }
    .word { font-size: {{ round($size * 1.3, 1) }}pt; font-weight: bold; }
    table.facts { width: 100%; border-collapse: collapse; margin-top: 6pt; }
    table.facts td { padding: 1.5pt 0; vertical-align: top; }
    table.facts td.label { color: #333333; width: 42%; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 3pt; }
    table.grid th { text-align: {{ $start }}; font-weight: normal; border-bottom: 0.6pt solid #000000; padding: 2pt 1pt; }
    table.grid td { padding: 2pt 1pt; }
    table.grid th.num, table.grid td.num { text-align: {{ $end }}; }
    .owed { width: 100%; border-collapse: collapse; margin-top: 6pt; border-top: 0.6pt dashed #000000; border-bottom: 0.6pt dashed #000000; }
    .owed td { padding: 4pt 0; }
    .owed .figure { font-size: {{ round($size * 1.4, 1) }}pt; font-weight: bold; text-align: {{ $end }}; }
    .stamp { border: 1pt solid #000000; padding: 2pt 6pt; font-weight: bold; }
    table.closing { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    .foot { text-align: center; margin-top: 8pt; font-size: {{ round($size * 0.85, 1) }}pt; border-top: 0.6pt dashed #000000; padding-top: 4pt; }
</style>
</head>
<body>
<div class="shop">
    @if (! empty($branding['logo']))
        <img src="{{ $branding['logo'] }}" style="height: 22pt"><br>
    @endif
    <span class="word">{{ $branding['name'] ?? 'qistas' }}</span>
    @foreach ($details as $line)
        <br><span class="small">{{ $line }}</span>
    @endforeach
</div>

@yield('content')

<div class="foot">{{ ($branding['qistas'] ?? true) ? __('Made with Qistas') : ($branding['footer'] ?? '') }}</div>
</body>
</html>
