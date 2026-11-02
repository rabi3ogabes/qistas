{{-- One line of a person's sign-in history, laid out; the wording is App\Auth\SignInPresenter's, so the security page and
     the admin business page say the same thing. Put it in an <ol class="signin-list">. Staff also see the address of the
     connection. The user agent is never printed, and every value is escaped. --}}
@props(['event', 'zone' => 'UTC', 'staff' => false])
@php $row = \App\Auth\SignInPresenter::present($event, $zone); @endphp
<li class="signin-row">
    <p class="signin-device">
        <span>{{ $row['device'] }}</span>
        @if ($row['failed'])<span class="badge badge-warn">{{ __('Wrong password') }}</span>@endif
    </p>
    <p class="signin-meta">
        <span class="signin-verdict" data-level="{{ $row['level'] }}">{{ $row['verdict'] }}</span>
        <span aria-hidden="true">·</span>
        <time datetime="{{ $row['iso'] }}" title="{{ $row['exact'] }}">{{ $row['ago'] }}</time>
        @if ($staff)
            <span aria-hidden="true">·</span>
            <bdi class="signin-ip" dir="ltr">{{ $row['ip'] }}</bdi>
        @endif
    </p>
    @if ($row['hint'])<p class="signin-hint field-hint">{{ $row['hint'] }}</p>@endif
</li>
