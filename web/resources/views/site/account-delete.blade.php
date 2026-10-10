{{-- How to delete a Qistas account and what happens to the data. Public: Google Play links to it from the store listing. --}}
<x-layouts.site :title="__('Delete your Qistas account')" path="/account/delete">
    <div class="container prose-page">
        <article class="prose">
            <h1>{{ __('Delete your Qistas account') }}</h1>
            <p>{{ __('You can delete your account yourself, at any time, from the app or from this website. Nobody needs to approve it.') }}</p>

            <h2>{{ __('How to do it') }}</h2>
            <ol>
                <li>{{ __('In the app: Settings, then Security, then Delete my account.') }}</li>
                <li>{{ __('On the website: sign in, open your account menu, then Security, then Delete my account.') }}</li>
                <li>{{ __('Confirm with your password (and your authentication code if two-step sign-in is on).') }}</li>
            </ol>

            <h2>{{ __('What happens to your data') }}</h2>
            <ul>
                <li>{{ __('If you own the business, it turns read-only at once and is erased after :days days: its customers, contracts, payments, files and the logins of people who belong to no other business. You can restore it until then.', ['days' => $days]) }}</li>
                <li>{{ __('If you are a member, your login is erased at once and the business keeps its books.') }}</li>
                <li>{{ __('Download your data first if you want to keep a copy: once erased, it cannot be brought back.') }}</li>
            </ul>

            <p>{{ __('Questions about your data? Write to us from the e-mail address you sign in with.') }}</p>
            <p><a class="btn btn-gold" href="{{ route('app.account.delete.show') }}">{{ __('Delete my account') }}</a></p>
        </article>
    </div>
</x-layouts.site>
