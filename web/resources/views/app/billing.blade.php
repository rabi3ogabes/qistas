{{-- Plan and billing: the plan in force and how it was paid, what it uses, and (Win Plan PP13) paying by bank transfer
     with the receipt, for the owner while the platform has it on. --}}
@php
    use App\Support\Format;
    use App\Support\Money;
    use Illuminate\Support\Str;

    $sourceLine = [
        'play' => __('Paid through Google Play. Change or cancel it in the Play Store on your phone.'),
        'manual' => __('Paid by bank transfer.'),
        'admin' => __('Given by the Qistas team.'),
    ];
    $statusBadge = [
        'pending' => ['badge-warn', __('Waiting for the Qistas team')],
        'approved' => ['badge-ok', __('Approved')],
        'rejected' => ['badge-bad', __('Not confirmed')],
    ];
    $periodLabel = ['month' => __('One month'), 'year' => __('One year')];
    $waiting = $requests->firstWhere('status', 'pending');
    $offerTransfer = $transfer && $bankDetails !== null;
@endphp
<x-layouts.app :title="__('Plan and billing')" section="billing">
    <x-page-head :title="__('Plan and billing')">
        <x-slot:subtitle>{{ __('What your workspace is on, and what the other plan adds.') }}</x-slot:subtitle>
    </x-page-head>


    <section class="card card-pad" aria-labelledby="your-plan">
        <h2 id="your-plan" class="checklist-title">{{ __('Your plan') }}: {{ $current->name }}</h2>
        @if ($subscription)
            <p class="plan-until">
                @if ($subscription->current_period_end){{ __('Until :date.', ['date' => $subscription->current_period_end->translatedFormat('j F Y')]) }}@endif
                {{ $sourceLine[$subscription->source] ?? '' }}
            </p>
        @endif
        <div class="usage">
            <x-meter :label="__('Customers')" :entitlement="$usage['customers']" />
            <x-meter :label="__('Active contracts')" :entitlement="$usage['contracts']" />
        </div>
    </section>

    @if ($offerTransfer)
        <section class="card card-pad stack transfer" aria-labelledby="transfer-title" style="margin-top:1rem">
            <div>
                <h2 id="transfer-title" class="checklist-title">{{ $current->is_default ? __('Pay by bank transfer') : __('Renew by bank transfer') }}</h2>
                <p class="muted">{{ __('Transfer the amount, then send its reference and receipt here. The Qistas team checks it and turns the plan on, usually within one working day.') }}</p>
            </div>

            @foreach ($requests->take(1) as $request)
                @php
                    [$tone, $label] = $statusBadge[$request->status] ?? ['', $request->status];
                @endphp
                <div class="transfer-status" role="status">
                    <span class="badge {{ $tone }}">{{ $label }}</span>
                    <span>{{ __(':plan, :period', ['plan' => $request->plan?->name, 'period' => $periodLabel[$request->period] ?? $request->period]) }}</span>
                    <span class="money">{{ Format::money($request->amount, $request->currency) }}</span>
                    <span class="cell-sub">{{ __('Reference :reference, sent :date', ['reference' => $request->reference, 'date' => $request->created_at->translatedFormat('j M Y')]) }}</span>
                    @if ($request->status === 'rejected' && $request->note)<p class="transfer-note">{{ $request->note }}</p>@endif
                </div>
            @endforeach

            @if ($waiting === null)
                <div class="transfer-to">
                    <p class="figure-label">{{ __('Transfer to') }}</p>
                    <p class="transfer-bank" dir="auto">{{ $bankDetails }}</p>
                </div>

                <form method="POST" action="{{ route('app.billing.manual.store') }}" enctype="multipart/form-data" class="form stack" novalidate>
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-transfer-'.Str::uuid()) }}">
                    @error('request')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

                    <fieldset class="field field-wide choice">
                        <legend class="field-label">{{ __('What you paid for') }}</legend>
                        @foreach ($paidPlans as $plan)
                            @foreach (['month' => $plan->price_monthly, 'year' => $plan->price_yearly] as $period => $price)
                                @if ($price !== null && Money::isPositive($price))
                                    <label class="choice-item">
                                        <input type="radio" name="offer" value="{{ $plan->key }}:{{ $period }}" @checked(old('offer', $paidPlans->first()?->key.':year') === $plan->key.':'.$period) required>
                                        <span><strong>{{ __(':plan, :period', ['plan' => $plan->name, 'period' => $periodLabel[$period]]) }}</strong><small class="money">{{ Format::money($price, $plan->currency) }}</small></span>
                                    </label>
                                @endif
                            @endforeach
                        @endforeach
                    </fieldset>
                    @error('period')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @error('plan')<p class="field-error" role="alert">{{ $message }}</p>@enderror

                    <div class="field">
                        <label for="transfer-method">{{ __('How you paid') }}</label>
                        <select id="transfer-method" name="method">
                            <option value="bank_transfer" @selected(old('method', 'bank_transfer') === 'bank_transfer')>{{ __('Bank transfer') }}</option>
                            <option value="wallet" @selected(old('method') === 'wallet')>{{ __('Wallet (STC Pay, urpay and the like)') }}</option>
                        </select>
                    </div>
                    <x-field name="reference" :label="__('Transfer reference')" maxlength="100" dir="ltr" autocomplete="off" required :hint="__('As your bank or wallet shows it.')" />
                    <div class="field">
                        <label for="transfer-receipt">{{ __('Receipt') }}</label>
                        <input id="transfer-receipt" type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" required @error('receipt') aria-invalid="true" aria-describedby="transfer-receipt-error" @enderror>
                        <p class="field-hint">{{ __('A photo, a screenshot or the PDF from your bank, up to 5 MB.') }}</p>
                        @error('receipt')<p class="field-error" id="transfer-receipt-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div><button class="btn btn-gold" type="submit">{{ __('Send the receipt') }}</button></div>
                </form>
            @endif
        </section>
    @elseif ($current->isFree())
        <section class="card card-pad" aria-labelledby="upgrade" style="margin-top:1rem">
            <h2 id="upgrade" class="checklist-title">{{ __('Move to Pro') }}</h2>
            <p>{{ __('Online payment is not switched on yet. Until it is, the Qistas team turns Pro on for your workspace: write to us from the e-mail address you sign in with and say which workspace it is for.') }}</p>
            <p>
                <strong>{{ __('Workspace') }}:</strong> {{ $tenantName }}
                @if ($supportEmail)
                    <br><a class="btn btn-gold" style="margin-top:.75rem" href="mailto:{{ $supportEmail }}?subject={{ rawurlencode(__('Pro for :workspace', ['workspace' => $tenantName])) }}">{{ __('Ask for Pro') }}</a>
                @endif
            </p>
            <p class="field-hint">{{ __('Your records are never deleted if you go back to Free: they just stay in view while the Free limits apply to anything new.') }}</p>
        </section>
    @endif

    <div class="card compare table-wrap" style="margin-top:1rem">
        <table class="table">
            <caption class="sr-only">{{ __('What each plan includes') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Feature') }}</th>
                    @foreach ($offers as $offer)<th scope="col">{{ $offer->plan->name }}@if ($offer->plan->key === $current->key) <span class="badge">{{ __('Your plan') }}</span>@endif</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($features as $feature)
                    <tr>
                        <th scope="row">{{ $feature->label() }}</th>
                        @foreach ($offers as $offer)
                            @php($row = $offer->feature($feature))
                            <td>
                                <span class="cell {{ $row['enabled'] ? 'yes' : 'no' }}">
                                    @if ($feature->type() === \App\Entitlements\FeatureType::Toggle)
                                        <x-icon :name="$row['enabled'] ? 'check' : 'x'" :size="18" />
                                        <span class="sr-only">{{ $row['short'] }}</span>
                                    @else
                                        @if (! $row['enabled'])<x-icon name="x" :size="18" />@endif
                                        <span @class(['sr-only' => ! $row['enabled']])>{{ $row['short'] }}</span>
                                    @endif
                                </span>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
