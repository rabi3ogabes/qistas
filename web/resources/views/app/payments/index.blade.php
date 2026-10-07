@php
    use App\Support\Format;
    use App\Support\PaymentMethods;
@endphp
<x-layouts.app :title="__('Payments')" section="payments">
    <x-page-head :title="__('Payments')">
        <x-slot:subtitle>{{ __('Every payment received, newest first. To take one, open the contract it belongs to.') }}</x-slot:subtitle>
    </x-page-head>

    <section class="card">
        @if ($lines->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="wallet" :size="24" /></span>
                <h3>{{ __('No payments yet') }}</h3>
                <p>{{ __('Payments you record on a contract appear here.') }}</p>
                <a class="btn btn-quiet" href="{{ route('app.contracts.index') }}">{{ __('Go to contracts') }}</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Customer') }}</th>
                            <th scope="col">{{ __('Contract') }}</th>
                            <th scope="col">{{ __('Details') }}</th>
                            <th scope="col">{{ __('Taken by') }}</th>
                            <th scope="col" class="num">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php
                                $kind = match (true) {
                                    $line->type === 'reversal' => 'reversal',
                                    $line->type === 'down_payment' => 'down_payment',
                                    isset($reversed[$line->id]) => 'voided',
                                    default => 'payment',
                                };
                            @endphp
                            <tr data-line="{{ $kind }}">
                                <td data-label="{{ __('Date') }}" class="money">{{ $line->paid_at->translatedFormat('j M Y') }}</td>
                                <td data-label="{{ __('Customer') }}">{{ $line->customer?->name }}</td>
                                <td data-label="{{ __('Contract') }}"><a class="cell-link" href="{{ route('app.contracts.show', $line->contract_id) }}">{{ $line->contract->reference() }}</a></td>
                                <td data-label="{{ __('Details') }}">
                                    <span class="line-title">
                                        @if ($kind === 'reversal') {{ __('Payment voided') }}
                                        @elseif ($kind === 'down_payment') {{ __('Down payment') }}
                                        @else {{ PaymentMethods::label($line->method) }}
                                        @endif
                                        @if ($kind === 'voided')<span class="badge badge-bad">{{ __('Voided') }}</span>@endif
                                    </span>
                                    @if ($line->note)<span class="cell-sub">{{ $line->note }}</span>@endif
                                </td>
                                <td data-label="{{ __('Taken by') }}">{{ $line->createdBy?->name ?? '—' }}</td>
                                <td data-label="{{ __('Amount') }}" @class(['num', 'money', 'voided' => $kind === 'voided'])>{{ Format::money($line->amount, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $lines->links() }}
</x-layouts.app>
