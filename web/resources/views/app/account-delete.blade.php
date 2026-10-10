{{-- Delete my account: what will happen, said plainly, then the confirmation. The owner deletes the business; anyone else their login. --}}
<x-layouts.app :title="__('Delete my account')" section="tools">
    <x-page-head :title="__('Delete my account')" />

    <section class="card card-pad" style="max-width:44rem">
        @if ($isOwner && $tenant->isBeingDeleted())
            <p>{{ __('This business will be deleted on :date. You can restore it until then.', ['date' => $tenant->restoreUntil()]) }}</p>
            <form method="post" action="{{ route('app.account.delete.destroy') }}">
                @csrf
                @method('DELETE')
                <x-button>{{ __('Restore the business') }}</x-button>
            </form>
        @else
            @if ($isOwner)
                <h2 class="card-title" style="margin-bottom:.5rem">{{ __('Delete :name and your account', ['name' => $tenant->name]) }}</h2>
                <ul class="plain-list" style="margin:0 0 1rem;padding-inline-start:1.2rem">
                    <li>{{ __('The business turns read-only at once for everyone in it.') }}</li>
                    <li>{{ __('After :days days its customers, contracts, payments and files are erased for good, with the logins of people who belong to no other business.', ['days' => $days]) }}</li>
                    <li>{{ __('Until then you can restore it with one tap, exactly as it was.') }}</li>
                </ul>
            @else
                <h2 class="card-title" style="margin-bottom:.5rem">{{ __('Delete your login') }}</h2>
                <p>{{ __('You leave :name at once. The business and its books stay as they are. Your login is erased unless you belong to another business.', ['name' => $tenant->name]) }}</p>
            @endif

            <form method="post" action="{{ route('app.account.delete.store') }}" class="form" novalidate>
                @csrf
                <x-field name="password" type="password" :label="__('Your password')" autocomplete="current-password" required />
                @if ($twoFactor)
                    <x-field name="code" :label="__('Authentication code')" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" />
                @endif
                @if ($isOwner)
                    <x-field name="confirm_name" :label="__('Type the business name to confirm: :name', ['name' => $tenant->name])" autocomplete="off" required />
                @endif
                <x-button variant="danger">{{ $isOwner ? __('Delete the business') : __('Delete my login') }}</x-button>
            </form>
        @endif
    </section>
</x-layouts.app>
