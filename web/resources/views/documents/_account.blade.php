{{-- An account, line by line, with a running balance ($statement: App\Domain\Ledger\Statement). $showContract adds the
     contract's number to each line, for a customer with several. --}}
@php
    use App\Support\Format;
    use App\Support\PaymentMethods;

    $describe = fn (array $line): string => match ($line['kind']) {
        'sale' => $line['contract']->title !== null ? __('Sold: :what', ['what' => $line['contract']->title]) : __('Sale on the contract'),
        'down_payment' => __('Down payment'),
        'payment' => __('Payment').' ('.PaymentMethods::label($line['transaction']->method).')',
        'reversal' => __('Payment voided'),
        'charge' => __('They took'),
        'charge_reversal' => __('Taken back off the balance'),
        'cancelled' => __('Contract cancelled'),
        default => $line['kind'],
    };
@endphp
<table class="grid">
    <thead>
        <tr>
            <th>{{ __('Date') }}</th>
            @if ($showContract)<th>{{ $options->word('contract') }}</th>@endif
            <th>{{ __('Details') }}</th>
            <th class="num amount">{{ __('Charged') }}</th>
            <th class="num amount">{{ __('Paid') }}</th>
            <th class="num amount">{{ __('Running balance') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($options->from !== null)
            <tr class="opening">
                <td>{{ $options->from->translatedFormat('j M Y') }}</td>
                @if ($showContract)<td></td>@endif
                <td>{{ __('Balance brought forward') }}</td>
                <td class="num"></td><td class="num"></td>
                <td class="num">{{ Format::amount($statement->opening) }}</td>
            </tr>
        @endif
        @forelse ($statement->lines as $line)
            <tr>
                <td>{{ $line['date']->translatedFormat('j M Y') }}</td>
                @if ($showContract)<td>{{ $line['contract']->reference() }}</td>@endif
                <td>{{ $describe($line) }}@if ($line['transaction']?->note)<br><span class="muted small">{{ $line['transaction']->note }}</span>@endif</td>
                <td class="num">{{ $line['debit'] === null ? '' : Format::amount($line['debit']) }}</td>
                <td class="num">{{ $line['credit'] === null ? '' : Format::amount($line['credit']) }}</td>
                <td class="num">{{ Format::amount($line['balance']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $showContract ? 6 : 5 }}" class="muted">{{ __('Nothing in this period.') }}</td></tr>
        @endforelse
        <tr class="sum">
            <td colspan="{{ $showContract ? 5 : 4 }}">{{ __('Balance') }}</td>
            <td class="num">{{ Format::amount($statement->closing) }}</td>
        </tr>
    </tbody>
</table>
