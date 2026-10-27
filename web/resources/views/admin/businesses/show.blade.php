{{-- One business: its owner, its plan and what it uses of it, the plan's history, and (for a super admin) a new plan.
     Never its customers or money. --}}
@php
    use App\Http\Requests\Admin\ChangePlanRequest;
    use App\Models\Plan;

    $canChange = auth()->user()?->can('manage-plans') ?? false;
    $country = $tenant->country ? \Locale::getDisplayRegion('-'.$tenant->country, app()->getLocale()) : null;
    $periods = ['none' => __('No end date'), '1' => __('One month'), '3' => __('Three months'), '12' => __('Twelve months'), 'date' => __('Until a day I choose')];
    $planNames = $plans->pluck('name', 'key');
    $describe = fn (?array $state): string => $state === null ? '—' : ($planNames[$state['plan']] ?? $state['plan']).($state['until'] ? ' · '.__('until :date', ['date' => \Illuminate\Support\Carbon::parse($state['until'])->translatedFormat('j M Y')]) : '');

    // Pro by card (spec 2026-10-27 §8): what Stripe holds, read from the subscription Qistas keeps in step with it.
    $card = $subscription !== null && $subscription->source === 'stripe' && $subscription->stripe_subscription_id !== null ? $subscription : null;
    $cardRunning = $card?->isCurrent() ?? false;
    $cardDate = $card?->current_period_end?->translatedFormat('j M Y');
@endphp
<x-layouts.admin :title="$tenant->name" section="businesses">
    <x-page-head :title="$tenant->name">
        <x-slot:subtitle>
            {{ __('Signed up :date', ['date' => $tenant->created_at->translatedFormat('j M Y')]) }}
            · <a class="link" href="{{ route('admin.businesses.index') }}">{{ __('All businesses') }}</a>
        </x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <div class="stack">
            <section class="card card-pad stack" aria-labelledby="membership-title">
                <h2 id="membership-title" class="card-title">{{ __('Membership') }}</h2>
                <div class="biz-plan">@include('admin.businesses._plan', ['plan' => $plan, 'subscription' => $subscription])</div>

                @if ($card)
                    <dl class="facts biz-card">
                        <div><dt>{{ __('Paid through') }}</dt><dd>{{ __('Card (Stripe)') }}</dd></div>
                        <div><dt>{{ __('Status') }}</dt><dd>{{ match (true) {
                            ! $cardRunning => __('Ended'),
                            $card->status === 'trialing' => __('Free trial'),
                            $card->status === 'past_due' => __('Payment failed'),
                            default => __('Active'),
                        } }}</dd></div>
                        <div><dt>{{ __('Period') }}</dt><dd>{{ $card->period === 'month' ? __('Monthly') : __('Yearly') }}</dd></div>
                        <div><dt>{{ __('What comes next') }}</dt><dd>{{ match (true) {
                            $cardDate === null => '—',
                            ! $cardRunning => __('Ended on :date', ['date' => $cardDate]),
                            $card->status === 'past_due' => __('Stripe is trying the card again'),
                            ! $card->auto_renew => __('Ends on :date, will not renew', ['date' => $cardDate]),
                            $card->status === 'trialing' => __('Free trial until :date', ['date' => $cardDate]),
                            default => __('Next charge on :date', ['date' => $cardDate]),
                        } }}</dd></div>
                        <div><dt>{{ __('In Stripe') }}</dt><dd><a class="link" href="https://dashboard.stripe.com/{{ $tenant->stripe_livemode ? '' : 'test/' }}subscriptions/{{ $card->stripe_subscription_id }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $card->stripe_subscription_id }}</a></dd></div>
                    </dl>
                @endif
                @error('card')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

                @if ($canChange && $cardRunning)
                    <div class="stack biz-change">
                        <p class="alert alert-warning">
                            <strong>{{ __('This business pays for :plan by card.', ['plan' => $card->plan->name]) }}</strong>
                            {{ __('A plan change here would leave Stripe charging them, so cancel the card subscription first.') }}
                        </p>
                        <details class="bill-reject" @if ($errors->has('when')) open @endif>
                            <summary class="btn btn-quiet btn-sm">{{ __('Cancel the card subscription') }}</summary>
                            <div class="stack">
                                <form method="POST" action="{{ route('admin.businesses.card.cancel', $tenant) }}" class="stack">
                                    @csrf
                                    <input type="hidden" name="when" value="now">
                                    <p class="field-hint">{{ __('Stripe stops charging at once and refunds the time not used yet. The business moves to :free, with nothing deleted.', ['free' => Plan::default()->name]) }}</p>
                                    <div><button class="btn btn-sm" type="submit">{{ __('Cancel now and refund the unused time') }}</button></div>
                                </form>
                                @if ($card->auto_renew && $cardDate !== null)
                                    <form method="POST" action="{{ route('admin.businesses.card.cancel', $tenant) }}" class="stack">
                                        @csrf
                                        <input type="hidden" name="when" value="period_end">
                                        <p class="field-hint">{{ __(':plan stays on until :date, then the business moves to :free.', ['plan' => $card->plan->name, 'date' => $cardDate, 'free' => Plan::default()->name]) }}</p>
                                        <div><button class="btn btn-quiet btn-sm" type="submit">{{ __('Cancel at the end of the period') }}</button></div>
                                    </form>
                                @endif
                            </div>
                        </details>
                        @error('when')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                @elseif ($canChange)
                    <form method="POST" action="{{ route('admin.businesses.plan', $tenant) }}" class="form stack biz-change" novalidate>
                        @csrf
                        @method('PUT')
                        <h3 class="biz-change-title">{{ __('Change the plan') }}</h3>
                        <div class="field">
                            <label for="f-plan">{{ __('Plan') }}</label>
                            <select id="f-plan" name="plan" @error('plan') aria-invalid="true" @enderror>
                                @foreach ($plans as $option)
                                    <option value="{{ $option->key }}" @selected(old('plan', $plan->is_default ? 'pro' : $plan->key) === $option->key)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @error('plan')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="field">
                            <legend>{{ __('For how long') }}</legend>
                            <div class="biz-periods">
                                @foreach ($periods as $value => $label)
                                    <label class="check"><input type="radio" name="period" value="{{ $value }}" @checked(old('period', '1') === (string) $value)> <span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            <div class="biz-until">
                                <x-field name="until" type="date" :label="__('Last day')" :min="now()->addDay()->format('Y-m-d')" :hint="__('The plan stays on to the end of that day, then the business is on Free again, with nothing deleted.')" />
                            </div>
                            @error('period')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            <p class="field-hint">{{ __('Free never runs out, so a move to Free has no end date.') }}</p>
                        </fieldset>
                        <x-field type="textarea" name="reason" :label="__('Why')" rows="2" maxlength="500" required :hint="__('For example: paid by bank transfer, receipt 4471. It is kept with the change.')" />
                        <p class="field-hint">{{ __('Moving to a smaller plan never deletes anything: the business keeps all it has and only stops adding past the new limits.') }}</p>
                        <div><button class="btn btn-gold" type="submit">{{ __('Change the plan') }}</button></div>
                    </form>
                @else
                    <p class="field-hint">{{ __('Only a super admin can change a business’s plan.') }}</p>
                @endif
            </section>

            <section class="card card-pad stack" aria-labelledby="history-title">
                <h2 id="history-title" class="card-title">{{ __('Plan history') }}</h2>
                @if ($history->isEmpty())
                    <p class="field-hint">{{ __('No changes made from the admin area yet.') }}</p>
                @else
                    <ol class="biz-history">
                        @foreach ($history as $entry)
                            <li>
                                <p class="biz-history-what">
                                    <span>{{ $describe($entry->changes['before'] ?? null) }}</span>
                                    <x-icon name="arrowRight" :size="16" class="flip" />
                                    <strong>{{ $describe($entry->changes['after'] ?? null) }}</strong>
                                </p>
                                <p class="biz-history-why">{{ $entry->changes['reason'] ?? '' }}</p>
                                <p class="cell-sub">{{ __(':name, :when', ['name' => $staff[$entry->user_id] ?? __('Someone no longer on the team'), 'when' => $entry->created_at?->translatedFormat('j M Y, H:i')]) }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <div class="stack">
            <section class="card card-pad stack" aria-labelledby="who-title">
                <h2 id="who-title" class="card-title">{{ __('Business') }}</h2>
                <dl class="facts">
                    <div><dt>{{ __('Owner') }}</dt><dd>{{ $tenant->owner?->name ?? '—' }}</dd></div>
                    <div><dt>{{ __('Email') }}</dt><dd dir="ltr">{{ $tenant->owner?->email ?? '—' }}</dd></div>
                    <div><dt>{{ __('People in the business') }}</dt><dd class="money">{{ $members }}</dd></div>
                    @if ($country)<div><dt>{{ __('Country') }}</dt><dd>{{ $country }}</dd></div>@endif
                    <div><dt>{{ __('Currency') }}</dt><dd>{{ $tenant->currency }}</dd></div>
                    @if ($tenant->deletion_requested_at)
                        <div><dt>{{ __('Status') }}</dt><dd><span class="badge badge-bad">{{ __('Being deleted') }}</span></dd></div>
                    @endif
                </dl>
            </section>

            <section class="card card-pad stack" aria-labelledby="usage-title">
                <h2 id="usage-title" class="card-title">{{ __('What they use') }}</h2>
                @foreach ($usage as $row)
                    <x-meter :label="$row['label']" :entitlement="$row['entitlement']" />
                @endforeach
            </section>
        </div>
    </div>
</x-layouts.admin>
