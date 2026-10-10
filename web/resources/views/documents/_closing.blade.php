{{-- The end of a document: the shop's signature (when it has one and the section is on) and the QR code that opens the
     verification page, with the code in words for someone who types it. --}}
<table class="closing"><tr>
    <td style="text-align: {{ $rtl ? 'right' : 'left' }}">
        @if (! empty($signature))
            <img src="{{ $signature }}" style="height: 36pt"><br>
            <span class="muted small">{{ __('Signed for :shop', ['shop' => $shop]) }}</span>
        @endif
    </td>
    <td style="text-align: {{ $rtl ? 'left' : 'right' }}; width: 46%">
        <table style="border-collapse: collapse"><tr>
            <td class="muted small" style="text-align: {{ $rtl ? 'left' : 'right' }}; padding: 0 6pt; vertical-align: middle">
                {{ __('Scan to check this document') }}<br>
                {{ __('Code :code', ['code' => $code]) }}
            </td>
            <td><img src="{{ $qr }}" style="width: 50pt; height: 50pt"></td>
        </tr></table>
    </td>
</tr></table>
