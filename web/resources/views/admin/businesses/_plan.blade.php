{{-- A business's plan in a few words: the plan it is on today, and until when (and, for Pro by card, whether it renews).
     $subscription may be null (Free). --}}
@php
    $grace = (int) config('qistas.billing.grace_days');
    $end = $subscription?->current_period_end;
    $paid = $subscription !== null && ! $subscription->plan->is_default;
    $lapsed = $paid && ! $subscription->isCurrent();
    $inGrace = $paid && ! $lapsed && $end !== null && $end->isPast();
@endphp
<span class="badge {{ $plan->is_default ? '' : 'badge-pro' }}">{{ $plan->name }}</span>
@if ($lapsed && $end === null)
    <span class="cell-sub">{{ __('Ended (was :plan). Nothing was deleted.', ['plan' => $subscription->plan->name]) }}</span>
@elseif ($lapsed)
    <span class="cell-sub">{{ __('Ended on :date (was :plan). Nothing was deleted.', ['plan' => $subscription->plan->name, 'date' => $end->translatedFormat('j M Y')]) }}</span>
@elseif ($inGrace)
    <span class="cell-sub">{{ __('Ended on :date, kept until :grace while a payment arrives', ['date' => $end->translatedFormat('j M Y'), 'grace' => $end->copy()->addDays($grace)->translatedFormat('j M Y')]) }}</span>
@elseif ($paid && $end !== null && $subscription->source === 'stripe')
    <span class="cell-sub">{{ match (true) {
        $subscription->status === 'trialing' => __('By card, free trial until :date', ['date' => $end->translatedFormat('j M Y')]),
        $subscription->auto_renew => __('By card, renews on :date', ['date' => $end->translatedFormat('j M Y')]),
        default => __('By card, until :date', ['date' => $end->translatedFormat('j M Y')]),
    } }}</span>
@elseif ($paid && $end !== null)
    <span class="cell-sub">{{ __('Until :date', ['date' => $end->translatedFormat('j M Y')]) }}</span>
@elseif ($paid)
    <span class="cell-sub">{{ __('No end date') }}</span>
@endif
