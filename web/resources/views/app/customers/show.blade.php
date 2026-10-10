@php
    use App\Support\Format;

    $statusTone = ['active' => 'badge-info', 'settled' => 'badge-ok', 'cancelled' => 'badge-bad'];
    $statusLabel = ['active' => __('Active'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
    $whatsapp = $customer->whatsappUrl();
@endphp
<x-layouts.app :title="$customer->name" section="customers">
    <x-page-head :title="$customer->name">
        <x-slot:subtitle><a class="link" href="{{ route('app.customers.index') }}">{{ __('All customers') }}</a></x-slot:subtitle>
        <x-document-menu id="customer-statement" :action="route('app.customers.statement', $customer)" :label="__('Statement')" :period="true" :sections="['overdue', 'signature']" />
        @can('update', $customer)
            <a class="btn btn-quiet" href="{{ route('app.customers.edit', $customer) }}"><x-icon name="pencil" :size="18" /> {{ __('Edit') }}</a>
        @endcan
        @can('create', \App\Models\Contract::class)
            <a class="btn" href="{{ url('/app/contracts/create') }}?customer={{ $customer->id }}"><x-icon name="plus" :size="18" /> {{ __('Open a contract') }}</a>
        @endcan
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="contact-title">
            <h2 id="contact-title" class="card-title">{{ __('Contact') }}</h2>

            <div class="contact-actions">
                <a class="btn btn-quiet btn-sm" href="{{ $customer->telUrl() }}"><x-icon name="message" :size="16" /> {{ __('Call') }}</a>
                @if ($whatsapp)<a class="btn btn-quiet btn-sm" href="{{ $whatsapp }}" rel="noopener" target="_blank"><x-icon name="send" :size="16" /> {{ __('WhatsApp') }}</a>@endif
                @if ($customer->email)<a class="btn btn-quiet btn-sm" href="mailto:{{ $customer->email }}"><x-icon name="send" :size="16" /> {{ __('Email') }}</a>@endif
            </div>

            <dl class="facts">
                <div><dt>{{ __('Phone') }}</dt><dd class="money" dir="ltr">{{ $customer->phone }}</dd></div>
                @if ($customer->phone_secondary)<div><dt>{{ __('Second phone') }}</dt><dd class="money" dir="ltr">{{ $customer->phone_secondary }}</dd></div>@endif
                @if ($customer->email)<div><dt>{{ __('Email') }}</dt><dd dir="ltr">{{ $customer->email }}</dd></div>@endif
                @if ($customer->job)<div><dt>{{ __('Job or employer') }}</dt><dd>{{ $customer->job }}</dd></div>@endif
                @if ($customer->address)<div><dt>{{ __('Address') }}</dt><dd>{{ $customer->address }}</dd></div>@endif
                @if ($customer->maskedNationalId())<div><dt>{{ __('National ID') }}</dt><dd class="money" dir="ltr">{{ $customer->maskedNationalId() }}</dd></div>@endif
                @if ($customer->notes)<div><dt>{{ __('Notes') }}</dt><dd class="prewrap">{{ $customer->notes }}</dd></div>@endif
                <div><dt>{{ __('Customer since') }}</dt><dd>{{ $customer->created_at->translatedFormat('j F Y') }}</dd></div>
            </dl>

            @can('delete', $customer)
                <details class="confirm">
                    <summary class="btn btn-ghost btn-sm danger-text">{{ __('Delete customer') }}</summary>
                    <form method="POST" action="{{ route('app.customers.destroy', $customer) }}" class="stack">
                        @csrf @method('DELETE')
                        <p>{{ __('This removes :name from your lists and frees a place on your plan. Their contracts and payments stay in your records.', ['name' => $customer->name]) }}</p>
                        @error('customer')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        <button class="btn btn-danger btn-sm">{{ __('Delete customer') }}</button>
                    </form>
                </details>
            @endcan
            @error('customer')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </section>

        <section class="card" aria-labelledby="contracts-title">
            <div class="card-head"><h2 id="contracts-title">{{ __('Contracts') }}</h2></div>
            @if ($contracts->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon name="fileText" :size="24" /></span>
                    <h3>{{ __('No contracts yet') }}</h3>
                    <p>{{ __('Open a contract to set up this customer’s instalment plan.') }}</p>
                    @can('create', \App\Models\Contract::class)
                        <a class="btn" href="{{ url('/app/contracts/create') }}?customer={{ $customer->id }}">{{ __('Open a contract') }}</a>
                    @endcan
                </div>
            @else
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Contract') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="num">{{ __('Total') }}</th>
                                <th scope="col" class="num">{{ __('Owes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contracts as $contract)
                                <tr>
                                    <td data-label="{{ __('Contract') }}"><a class="cell-link" href="{{ url('/app/contracts/'.$contract->id) }}">{{ $contract->reference() }}</a></td>
                                    <td data-label="{{ __('Status') }}"><span class="badge {{ $statusTone[$contract->status] ?? '' }}">{{ $statusLabel[$contract->status] ?? $contract->status }}</span></td>
                                    <td data-label="{{ __('Total') }}" class="num money">{{ Format::money($contract->total, $currency) }}</td>
                                    <td data-label="{{ __('Owes') }}" class="num money">{{ Format::money($contract->status === 'cancelled' ? '0' : $owed[$contract->id], $currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
