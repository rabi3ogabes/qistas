{{-- Receipts for a card subscription, read from Stripe; each downloads as a PDF through Qistas (spec 2026-10-27 §4.3). --}}
@php
    use App\Support\Dates;
    use App\Support\Format;
@endphp
<section class="card table-wrap receipts" aria-labelledby="receipts-title" style="margin-top:1rem">
    <h2 id="receipts-title" class="checklist-title receipts-title">{{ __('Receipts') }}</h2>
    <table class="table table-stack">
        <thead>
            <tr>
                <th scope="col">{{ __('Date') }}</th>
                <th scope="col">{{ __('Receipt') }}</th>
                <th scope="col">{{ __('Amount') }}</th>
                <th scope="col">{{ __('Status') }}</th>
                <th scope="col"><span class="sr-only">{{ __('Download') }}</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipts as $receipt)
                <tr>
                    <td>{{ Dates::day($receipt->created) }}</td>
                    <td dir="ltr">{{ $receipt->number ?? '—' }}</td>
                    <td class="money">{{ Format::money($receipt->amount, strtoupper($receipt->currency)) }}</td>
                    <td>
                        @if ($receipt->status === 'paid')<span class="badge badge-ok">{{ __('Paid') }}</span>
                        @else<span class="badge badge-warn">{{ __('Waiting') }}</span>@endif
                    </td>
                    <td>
                        <a class="btn btn-quiet btn-sm" href="{{ route('app.billing.card.receipt', ['invoice' => $receipt->id]) }}" data-download>
                            <x-icon name="download" :size="16" /><span>{{ __('Download') }}</span>
                        </a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
