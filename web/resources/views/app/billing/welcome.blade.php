{{-- Pro by card: the moment after paying (spec 2026-10-27 §4.2). The plumb settles once, the brand's own gesture of a
     balance found; then the page is quiet: what is now open, when the first charge comes, and the way on. --}}
@php
    use App\Support\Dates;

    $date = Dates::day($subscription->current_period_end, 'j F Y');
@endphp
<x-layouts.app :title="__('Welcome to :plan', ['plan' => $plan->name])" section="billing">
    <section class="pro-welcome card" aria-labelledby="pro-welcome-title">
        <span class="pro-welcome-plumb" aria-hidden="true"></span>

        <h1 id="pro-welcome-title" class="display pro-welcome-title">{{ __('Welcome to :plan', ['plan' => $plan->name]) }}</h1>
        <p class="pro-welcome-lead">
            @if ($subscription->status === 'trialing')
                {{ __('Your free trial runs until :date. Nothing is charged before then.', ['date' => $date]) }}
            @else
                {{ __('Paid. :plan renews on :date.', ['plan' => $plan->name, 'date' => $date]) }}
            @endif
        </p>

        @if ($unlocked !== [])
            <h2 class="pro-welcome-list-title">{{ __('Now open in your workspace') }}</h2>
            <ul class="pro-list">
                @foreach ($unlocked as $line)
                    <li><x-icon name="check" :size="18" /><span>{{ $line }}</span></li>
                @endforeach
            </ul>
        @endif

        <div class="pro-welcome-actions">
            <a class="btn btn-gold" href="{{ route('app.dashboard') }}">{{ __('Go to your dashboard') }}</a>
            <a class="btn btn-quiet" href="{{ route('app.billing') }}">{{ __('Plan and billing') }}</a>
        </div>
        <p class="pro-welcome-note">{{ __('A receipt is in Plan and billing whenever you need it. You can cancel there at any time.') }}</p>
    </section>
</x-layouts.app>
