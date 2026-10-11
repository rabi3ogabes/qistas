{{-- Billing (Win Plan PP13): bank transfers waiting for a decision with their receipts, recent decisions, and how Qistas
     takes payment (automatic approval, the bank details owners pay to, Google Play). Staff look; a super admin decides. --}}
@php
    use App\Support\Format;

    $periodLabel = ['month' => __('One month'), 'year' => __('One year')];
    $methodLabel = ['bank_transfer' => __('Bank transfer'), 'wallet' => __('Wallet')];
    $statusLabel = ['approved' => __('Approved'), 'rejected' => __('Rejected')];
    $when = fn ($moment) => $moment?->translatedFormat('j M Y, H:i');
@endphp
<x-layouts.admin :title="__('Billing')" section="billing">
    <x-page-head :title="__('Billing')">
        <x-slot:subtitle>{{ __('Bank transfers waiting for the team, and how businesses pay for their plan.') }}</x-slot:subtitle>
    </x-page-head>

    @error('request')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

    <section class="card bill-queue" aria-labelledby="waiting-title">
        <div class="card-head">
            <h2 id="waiting-title">{{ __('Waiting for a decision') }}</h2>
            @if ($pending->isNotEmpty())<span class="badge badge-warn">{{ $pending->count() }}</span>@endif
        </div>
        @if ($pending->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="check" :size="24" /></span>
                <h3>{{ __('Nothing is waiting') }}</h3>
                <p>{{ $settings['auto_activate'] ? __('Automatic approval is on: plans turn on as soon as a receipt arrives.') : __('A transfer appears here as soon as an owner sends its receipt.') }}</p>
            </div>
        @else
            <ul class="bill-list">
                @foreach ($pending as $request)
                    <li class="bill-item">
                        <div class="bill-who">
                            <a class="cell-link" href="{{ route('admin.businesses.show', $request->tenant_id) }}">{{ $request->tenant?->name }}</a>
                            @if ($request->createdBy)<span class="cell-sub" dir="ltr">{{ $request->createdBy->email }}</span>@endif
                        </div>
                        <dl class="bill-facts">
                            <div><dt>{{ __('Plan') }}</dt><dd>{{ __(':plan, :period', ['plan' => $request->plan?->name, 'period' => $periodLabel[$request->period] ?? $request->period]) }}</dd></div>
                            <div><dt>{{ __('Amount') }}</dt><dd class="money">{{ Format::money($request->amount, $request->currency) }}</dd></div>
                            <div><dt>{{ $methodLabel[$request->method] ?? $request->method }}</dt><dd dir="ltr" class="bill-ref">{{ $request->reference }}</dd></div>
                            <div><dt>{{ __('Sent') }}</dt><dd class="money">{{ $when($request->created_at) }}</dd></div>
                        </dl>
                        <div class="bill-actions">
                            @if ($request->receipt)
                                <a class="btn btn-quiet btn-sm" href="{{ route('admin.billing.receipt', $request) }}" target="_blank" rel="noopener"><x-icon name="eye" :size="16" /> {{ __('Open the receipt') }}</a>
                            @endif
                            @if ($canDecide)
                                <form method="POST" action="{{ route('admin.billing.approve', $request) }}">
                                    @csrf
                                    <button class="btn btn-gold btn-sm" type="submit">{{ __('Approve and turn the plan on') }}</button>
                                </form>
                                <details class="bill-reject">
                                    <summary class="btn btn-ghost btn-sm">{{ __('Reject') }}</summary>
                                    <form method="POST" action="{{ route('admin.billing.reject', $request) }}" class="form stack">
                                        @csrf
                                        <x-field type="textarea" name="note" :id="'note-'.$request->id" :label="__('Why, for the owner')" rows="2" maxlength="500" required :hint="__('For example: no transfer with this reference reached the account.')" />
                                        <div><button class="btn btn-sm" type="submit">{{ __('Reject the transfer') }}</button></div>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($decided->isNotEmpty())
        <section class="card" aria-labelledby="decided-title" style="margin-top:1rem">
            <div class="card-head"><h2 id="decided-title">{{ __('Decided recently') }}</h2></div>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Business') }}</th>
                            <th scope="col">{{ __('Plan') }}</th>
                            <th scope="col" class="num">{{ __('Amount') }}</th>
                            <th scope="col">{{ __('Decision') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($decided as $request)
                            <tr>
                                <td data-label="{{ __('Business') }}"><span class="biz-cell"><span>{{ $request->tenant?->name }}</span><span class="cell-sub" dir="ltr">{{ $request->reference }}</span></span></td>
                                <td data-label="{{ __('Plan') }}">{{ __(':plan, :period', ['plan' => $request->plan?->name, 'period' => $periodLabel[$request->period] ?? $request->period]) }}</td>
                                <td data-label="{{ __('Amount') }}" class="num money">{{ Format::money($request->amount, $request->currency) }}</td>
                                <td data-label="{{ __('Decision') }}">
                                    <span class="biz-cell">
                                        <span class="badge {{ $request->status === 'approved' ? 'badge-ok' : 'badge-bad' }}">{{ $statusLabel[$request->status] ?? $request->status }}</span>
                                        <span class="cell-sub">{{ $request->decidedBy?->name ?? __('Automatically') }}, {{ $when($request->decided_at) }}</span>
                                        @if ($request->note)<span class="cell-sub">{{ $request->note }}</span>@endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card card-pad stack" aria-labelledby="settings-title" style="margin-top:1rem">
        <h2 id="settings-title" class="card-title">{{ __('How businesses pay') }}</h2>
        <p class="field-hint">{{ __('Each way is switched on or off for everyone in Feature control (Pro by bank transfer, Pro through Google Play).') }}</p>

        <form method="POST" action="{{ route('admin.billing.settings') }}" class="form stack" novalidate>
            @csrf
            @method('PUT')
            <fieldset class="stack bill-group" @disabled(! $canDecide)>
                <legend>{{ __('Bank transfer') }}</legend>
                <label class="check">
                    <input type="hidden" name="auto_activate" value="0">
                    <input type="checkbox" name="auto_activate" value="1" @checked(old('auto_activate', $settings['auto_activate']))>
                    <span>{{ __('Turn the plan on as soon as the receipt arrives, without waiting for the team') }}</span>
                </label>
                <x-field type="textarea" name="bank_details" :label="__('Where owners transfer to')" rows="4" maxlength="2000" :value="$settings['bank_details']" dir="auto"
                    :hint="__('The bank, the account name and the IBAN, as owners should see them. Shown on the website only, never in the app.')" />
            </fieldset>

            <fieldset class="stack bill-group" @disabled(! $canDecide)>
                <legend>{{ __('Google Play') }}</legend>
                <p class="bill-ready">
                    @if ($settings['play_ready'])
                        <span class="badge badge-ok">{{ __('Ready to sell in the app') }}</span>
                    @else
                        <span class="badge badge-warn">{{ __('Not ready yet') }}</span>
                        <span class="cell-sub">{{ __('It needs the package name, the service account and at least one product.') }}</span>
                    @endif
                </p>
                <x-field name="play_package" :label="__('Package name')" :value="$settings['play_package']" dir="ltr" autocomplete="off" placeholder="app.qistas" />
                <div class="field">
                    <label for="f-play_account">{{ __('Service account') }}</label>
                    <textarea id="f-play_account" name="play_account" rows="3" dir="ltr" autocomplete="off" spellcheck="false" placeholder="{{ __('Paste the JSON file Google gives') }}" @error('play_account') aria-invalid="true" aria-describedby="f-play_account-error" @enderror></textarea>
                    <p class="field-hint">
                        @if ($settings['play_account_email'])
                            {{ __('Saved: :email. Leave this empty to keep it; it is never shown again.', ['email' => $settings['play_account_email']]) }}
                        @else
                            {{ __('A Google Cloud service account with access to the app’s orders and subscriptions in the Play Console. It is stored encrypted.') }}
                        @endif
                    </p>
                    @error('play_account')<p class="field-error" id="f-play_account-error" role="alert">{{ $message }}</p>@enderror
                </div>
                @foreach ($paidPlans as $plan)
                    <x-field :name="'play_products['.$plan->key.']'" :id="'f-product-'.$plan->key" :label="__('Subscription ID for :plan', ['plan' => $plan->name])" :value="$settings['play_products'][$plan->key] ?? ''" dir="ltr" autocomplete="off" placeholder="qistas_{{ $plan->key }}" />
                @endforeach
                <x-field name="push_audience" :label="__('Notification audience')" :value="$settings['push_audience']" dir="ltr" autocomplete="off" :placeholder="$webhookUrl"
                    :hint="__('Google sends each purchase change to :url. In the Pub/Sub push subscription, turn on authentication and use this address as the audience.', ['url' => $webhookUrl])" />
                <x-field name="push_email" type="email" :label="__('Notification service account')" :value="$settings['push_email']" dir="ltr" autocomplete="off"
                    :hint="__('The service account the push subscription authenticates as. Messages signed by anyone else are refused.')" />
            </fieldset>

            @if ($canDecide)
                <div><button class="btn btn-gold" type="submit">{{ __('Save billing settings') }}</button></div>
            @else
                <p class="field-hint">{{ __('Only a super admin can change these.') }}</p>
            @endif
        </form>
    </section>
</x-layouts.admin>
