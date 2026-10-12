{{--
    The page a signing link opens on the customer's own phone (Win Plan K5, decision D6). Four states: the code (nothing
    about the contract is shown before it), the contract with the pad, thanks, and a closed link. Never indexed.
--}}
@php
    use App\Domain\Schedule\Frequencies;
    use App\Support\Dates;
    use App\Support\Format;
    use App\Support\Money;
@endphp
<x-layouts.site :title="__('Sign a contract')" :path="'/sign'" :index="false" :focused="true">
    <div class="container prose-page">
        <article class="prose sign-page">
            @if ($state === 'closed')
                <h1>{{ __('This signing link is no longer valid') }}</h1>
                <p>{{ __('It may have been used already, closed after wrong codes, or be more than a day old. Ask the shop for a new one.') }}</p>
            @elseif ($state === 'done')
                <p class="verify-seal"><x-icon name="check" :size="20" /> {{ __('Signed') }}</p>
                <h1>{{ __('Thank you') }}</h1>
                <p>{{ __('Your signature is kept with the contract. You can close this page.') }}</p>
            @elseif ($state === 'code')
                <p class="sign-shop">{{ $business }}</p>
                <h1>{{ __('Sign your contract') }}</h1>
                <p>{{ __('Enter the six-digit code the shop sent you by text message. It keeps the contract private to you.') }}</p>
                <form method="POST" action="{{ route('sign.code', ['token' => $token]) }}" class="form" novalidate>
                    @csrf
                    <x-field name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="12" dir="ltr" required autofocus />
                    <x-button variant="gold">{{ __('Continue') }}</x-button>
                </form>
            @else
                @php $money = fn (string $amount): string => Format::money($amount, $currency); @endphp
                <p class="sign-shop">{{ $business }}</p>
                <h1>{{ __('Read the contract, then sign') }}</h1>

                <table class="sign-facts">
                    <tr><th scope="row">{{ __('Contract') }}</th><td class="money" dir="ltr">{{ $contract->reference() }}</td></tr>
                    <tr><th scope="row">{{ __('Buyer') }}</th><td>{{ $contract->customer?->name }}</td></tr>
                    @foreach ($contract->items as $item)
                        <tr><th scope="row">{{ __('Item') }}</th><td>{{ $item->quantity > 1 ? $item->name.' ×'.$item->quantity : $item->name }}</td></tr>
                    @endforeach
                    <tr><th scope="row">{{ __('Price') }}</th><td class="money">{{ $money($contract->principal) }}</td></tr>
                    @if (Money::isPositive($contract->down_payment))
                        <tr><th scope="row">{{ __('Down payment') }}</th><td class="money">{{ $money($contract->down_payment) }}</td></tr>
                    @endif
                    <tr><th scope="row">{{ __('Total to repay') }}</th><td class="money"><strong>{{ $money($contract->total) }}</strong></td></tr>
                    @if ($contract->installment_count > 0)
                        <tr><th scope="row">{{ __('Instalments') }}</th><td>{{ $contract->installment_count }} · {{ Frequencies::label($contract->frequency) }}</td></tr>
                    @endif
                </table>

                @php $schedule = $contract->installments->reject(fn ($installment) => $installment->status === 'superseded')->values(); @endphp
                @if ($schedule->isNotEmpty())
                    <h2>{{ __('Schedule') }}</h2>
                    <table class="sign-schedule">
                        <thead><tr><th scope="col">#</th><th scope="col">{{ __('Due date') }}</th><th scope="col" class="num">{{ __('Amount') }}</th></tr></thead>
                        <tbody>
                            @foreach ($schedule as $installment)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ Dates::day($installment->due_date) }}</td>
                                    <td class="num money">{{ Format::amount($installment->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <h2>{{ __('Your signature') }}</h2>
                <form method="POST" action="{{ route('sign.store', ['token' => $token]) }}" class="signature-pad" data-signature-pad>
                    @csrf
                    <input type="hidden" name="drawing" value="">
                    <canvas aria-label="{{ __('Sign here with your finger, a pen or the mouse') }}" role="img"></canvas>
                    <p class="field-hint">{{ __('By signing you agree to this contract and its schedule.') }}</p>
                    @error('file')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <div class="form-actions">
                        <button class="btn btn-gold" type="submit" data-signature-save disabled>{{ __('Sign the contract') }}</button>
                        <button class="btn btn-ghost" type="button" data-signature-clear>{{ __('Start again') }}</button>
                    </div>
                </form>
            @endif
        </article>
    </div>
</x-layouts.site>
