@php
    use App\Actions\Team\TeamRules;
    use App\Tenancy\TenantRole;

    // One plain sentence per role, shown in the invitation form and beside each person.
    $roleNames = [
        'owner' => __('Owner'), 'manager' => __('Manager'), 'accountant' => __('Accountant'),
        'collector' => __('Collector'), 'viewer' => __('Viewer'),
    ];
    $roleHelp = [
        'manager' => __('Runs the business with you: customers, contracts, payments, reversals and the team.'),
        'accountant' => __('Records and checks payments and sees every report.'),
        'collector' => __('Collects instalments and records payments.'),
        'viewer' => __('Can look at everything and change nothing.'),
    ];
    $full = ! $entitlement->unlimited() && $entitlement->remaining() === 0;
    $link = session('invitation_url');
@endphp
<x-layouts.app :title="__('Team')" section="team">
    <x-page-head :title="__('Team')">
        <x-slot:subtitle>
            @if ($entitlement->unlimited())
                {{ __(':count people', ['count' => $entitlement->used()]) }}
            @else
                {{ __(':used of :limit people on your plan', ['used' => $entitlement->used(), 'limit' => $entitlement->limit()]) }}
            @endif
        </x-slot:subtitle>
    </x-page-head>

    @if ($link)
        {{-- Shown once: only a fingerprint of the link is kept. --}}
        <section class="card card-pad" style="margin-bottom:1rem" aria-labelledby="link-title">
            <h2 id="link-title" class="card-title" style="margin-bottom:.5rem">{{ __('Share this link with the person you invited') }}</h2>
            <p class="field-hint">{{ __('It works once, for 7 days. Anyone with the link can join, so send it only to them.') }}</p>
            <p><input class="input" type="text" value="{{ $link }}" readonly dir="ltr" aria-label="{{ __('Invitation link') }}" onclick="this.select()" style="width:100%"></p>
            <p style="display:flex;gap:.5rem;flex-wrap:wrap">
                <a class="btn btn-gold btn-sm" href="https://wa.me/?text={{ rawurlencode(__('Join :name on Qistas:', ['name' => $tenant->name]).' '.$link) }}" target="_blank" rel="noopener noreferrer">{{ __('Send on WhatsApp') }}</a>
            </p>
        </section>
    @endif

    <section class="card" style="margin-bottom:1rem">
        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Person') }}</th>
                        <th scope="col">{{ __('Role') }}</th>
                        @if ($canManage)<th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        @php($role = $member['role'])
                        <tr>
                            <td data-label="{{ __('Person') }}">
                                <strong>{{ $member['name'] }}</strong>@if ($member['id'] === auth()->id()) <span class="badge">{{ __('You') }}</span>@endif
                                <br><span class="field-hint" dir="ltr">{{ $member['email'] }}</span>
                            </td>
                            <td data-label="{{ __('Role') }}">
                                @if ($canManage && TeamRules::canTouch($myRole, $role))
                                    <form method="post" action="{{ route('app.team.members.update', $member['id']) }}" style="display:flex;gap:.5rem;align-items:center">
                                        @csrf
                                        @method('PUT')
                                        <label class="sr-only" for="role-{{ $member['id'] }}">{{ __('Role of :name', ['name' => $member['name']]) }}</label>
                                        <select id="role-{{ $member['id'] }}" name="role">
                                            @foreach ($assignable as $option)
                                                <option value="{{ $option->value }}" @selected($option === $role)>{{ $roleNames[$option->value] }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-quiet btn-sm" type="submit">{{ __('Save') }}</button>
                                    </form>
                                @else
                                    {{ $roleNames[$role->value] }}
                                @endif
                            </td>
                            @if ($canManage)
                                <td>
                                    @if (TeamRules::canTouch($myRole, $role))
                                        <form method="post" action="{{ route('app.team.members.destroy', $member['id']) }}" onsubmit="return confirm(@js(__('Remove :name from the team? They are signed out of this business at once.', ['name' => $member['name']])))">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm" type="submit">{{ __('Remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td data-label="{{ __('Person') }}">
                                <strong>{{ $invitation->name ?? __('Invited') }}</strong> <span class="badge">{{ __('Waiting') }}</span>
                                <br><span class="field-hint">{{ __('Link works until :date', ['date' => $invitation->expires_at->format('Y-m-d')]) }}</span>
                            </td>
                            <td data-label="{{ __('Role') }}">{{ $roleNames[$invitation->role->value] }}</td>
                            @if ($canManage)
                                <td>
                                    <form method="post" action="{{ route('app.team.invitations.destroy', $invitation->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost btn-sm" type="submit">{{ __('Withdraw') }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($canManage)
        <section class="card card-pad" aria-labelledby="invite-title">
            <h2 id="invite-title" class="card-title" style="margin-bottom:.75rem">{{ __('Invite someone') }}</h2>
            @if ($full)
                <p>{{ __('Your plan has room for :limit people. Upgrade to add more.', ['limit' => $entitlement->limit()]) }}</p>
                <a class="btn btn-gold" href="{{ route('app.billing') }}">{{ __('See plans') }}</a>
            @else
                <form method="post" action="{{ route('app.team.invitations.store') }}" class="form" novalidate>
                    @csrf
                    <fieldset class="field">
                        <legend>{{ __('What they may do') }}</legend>
                        @foreach ($assignable as $option)
                            <label style="display:block;margin-block:.4rem">
                                <input type="radio" name="role" value="{{ $option->value }}" @checked(old('role', 'collector') === $option->value)>
                                <strong>{{ $roleNames[$option->value] }}</strong> <span class="field-hint">{{ $roleHelp[$option->value] }}</span>
                            </label>
                        @endforeach
                        @error('role')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                    <x-field name="name" :label="__('Their name (optional)')" autocomplete="off" />
                    <x-field name="phone" type="tel" :label="__('Their phone (optional)')" autocomplete="off" />
                    <x-button>{{ __('Make an invitation link') }}</x-button>
                </form>
            @endif
        </section>
    @endif
</x-layouts.app>
