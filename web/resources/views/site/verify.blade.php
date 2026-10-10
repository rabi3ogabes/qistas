{{-- The page a document's QR code opens: the shop issued it, what it is, its reference, the day and the total. Nothing about
     the customer. --}}
@php
    use App\Support\Format;

    $kinds = [
        'customer_statement' => __('Statement of account'),
        'contract_statement' => __('Statement of account'),
        'transactions_report' => __('Payments report'),
        'investor_report' => __('Investor report'),
        'receipt' => __('Receipt'),
    ];
@endphp
<x-layouts.site :title="__('Check a document')" :path="'/verify'" :index="false">
    <div class="container prose-page">
        <article class="prose verify-card" style="max-width:34rem;margin-inline:auto">
            <p class="verify-seal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                {{ __('Issued with Qistas') }}
            </p>
            <h1>{{ $kinds[$kind] ?? __('Document') }}</h1>
            <p>{{ __('This document was issued by :shop.', ['shop' => $shop]) }}</p>
            <dl class="verify-facts">
                <div><dt>{{ __('Reference') }}</dt><dd class="money" dir="ltr">{{ $reference }}</dd></div>
                <div><dt>{{ __('Issued on') }}</dt><dd class="money">{{ $issuedAt->translatedFormat('j M Y') }}</dd></div>
                @if ($total !== null)
                    <div><dt>{{ __('Total') }}</dt><dd class="money">{{ Format::money($total, $currency) }}</dd></div>
                @endif
            </dl>
            <p class="field-hint">{{ __('If the paper in your hands shows something else, it was changed after it was issued.') }}</p>
        </article>
    </div>
</x-layouts.site>
