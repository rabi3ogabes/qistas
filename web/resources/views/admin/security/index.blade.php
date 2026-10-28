{{-- Team security: whether the admin team must use two-factor (optional until a super admin requires it), and who on
     the team uses it. Staff look; a super admin changes the rule. --}}
@php
    $roleName = ['super_admin' => __('Super admin'), 'admin' => __('Admin')];
    $ownOff = ! $me->hasConfirmedTwoFactor();
@endphp
<x-layouts.admin :title="__('Team security')" section="team-security">
    <x-page-head :title="__('Team security')">
        <x-slot:subtitle>{{ __('Whether the admin team must use two-factor, and who already does.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="rule-title">
            <div class="team-rule-head">
                <h2 id="rule-title" class="card-title">{{ __('Two-factor for the team') }}</h2>
                <span class="badge {{ $required ? 'badge-ok' : '' }}">{{ $required ? __('Required') : __('Optional') }}</span>
            </div>

            <form method="POST" action="{{ route('admin.security.update') }}" class="form stack" novalidate>
                @csrf
                @method('PUT')
                <fieldset class="choice team-rule" @disabled(! $canChange)>
                    <legend class="field-label">{{ __('The admin area') }}</legend>
                    <label class="choice-item">
                        <input type="radio" name="required" value="0" @checked(! old('required', $required))>
                        <span>
                            <strong>{{ __('Optional') }}</strong>
                            <small>{{ __('Each person turns two-factor on or off on their own security page. The admin area opens either way.') }}</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="required" value="1" @checked(old('required', $required))>
                        <span>
                            <strong>{{ __('Required') }}</strong>
                            <small>{{ __('The admin area opens only with two-factor. Anyone without it is asked to set it up the next time they open it.') }}</small>
                        </span>
                    </label>
                </fieldset>
                @error('required')<p class="field-error" role="alert">{{ $message }}</p>@enderror

                @if ($canChange)
                    @if ($ownOff)
                        <p class="field-hint">{{ __('Your own two-factor is off. Turn it on before requiring it for the team.') }} <a class="link" href="{{ route('security') }}">{{ __('Open your security page') }}</a></p>
                    @endif
                    <div><button class="btn btn-gold" type="submit">{{ __('Save the rule') }}</button></div>
                @else
                    <p class="field-hint">{{ __('Only a super admin can change this.') }}</p>
                @endif
            </form>
        </section>

        <section class="card card-pad stack" aria-labelledby="team-title">
            <h2 id="team-title" class="card-title">{{ __('Who uses it') }}</h2>
            <p class="field-hint">{{ __(':count of :total people on the team use two-factor.', ['count' => $protected, 'total' => $staff->count()]) }}</p>
            <ul class="team-list">
                @foreach ($staff as $person)
                    <li>
                        <span class="team-who">
                            <span class="team-name">{{ $person->name }}@if ($person->is($me)) <span class="team-you">{{ __('(you)') }}</span>@endif</span>
                            <span class="cell-sub" dir="ltr">{{ $person->email }}</span>
                        </span>
                        <span class="team-tags">
                            <span class="cell-sub">{{ $roleName[$person->platform_role] ?? $person->platform_role }}</span>
                            @if ($person->status === 'suspended')<span class="badge badge-bad">{{ __('Suspended') }}</span>@endif
                            @if ($person->hasConfirmedTwoFactor())
                                <span class="badge badge-ok"><x-icon name="shield" :size="14" /> {{ __('Two-factor on') }}</span>
                            @else
                                <span class="badge">{{ __('Two-factor off') }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="field-hint">{{ __('Each person turns their own two-factor on or off on their security page, after typing their password again. Every change is kept in the audit log.') }}</p>
        </section>
    </div>
</x-layouts.admin>
