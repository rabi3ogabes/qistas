{{--
    The first-run card (go-live basics, spec 2026-11-01 §3.3), at the top of the dashboard until the owner has named the
    business: one required field, and the place the sign-up found, told in a sentence the owner can accept or change.
    - The owner sees the card; anyone else in the business reads one quiet line where it would be.
    - The place: found from the network, the device's time zone or the browser's language (and a country on the list) = a
      sentence with a "Change" that opens the chooser (a guess from the time zone or the language is flagged as one,
      because either can mislead);
      found by neither = the chooser at once; a business with a contract or a payment = the name only, and why.
    - The card never sends a time zone: the server derives it from the country. Saved by PUT /app/setup (SetupController).
--}}
@props(['tenant'])
@php
    $isOwner = auth()->user()?->roleIn($tenant->id) === \App\Tenancy\TenantRole::Owner;
@endphp
@unless ($tenant->isSetUp())
    @if ($isOwner)
        @php
            $countries = \App\Support\Countries::options();
            $country = strtoupper((string) $tenant->country);
            $listed = array_key_exists($country, $countries);
            $source = (string) $tenant->country_source;
            $open = app(\App\Actions\ConfirmWorkspaceSetup::class)->regionIsOpen($tenant);
            // Found by the network or the browser, and a country a business can be opened in: a sentence to accept.
            $detected = $open && $listed && in_array($source, ['header', 'timezone', 'language'], true);
            $picked = (string) old('country', $listed ? $country : '');
        @endphp
        <section id="setup-card" class="card card-pad setup-card" aria-labelledby="setup-title">
            <header class="setup-head">
                <span class="setup-mark" aria-hidden="true"><x-icon name="fileText" :size="22" /></span>
                <div>
                    <h2 id="setup-title" class="card-title">{{ __('Name your business') }}</h2>
                    <p class="muted">{{ __('It is printed on every receipt and statement.') }}</p>
                </div>
            </header>

            <form method="POST" action="{{ route('app.setup.update') }}" class="form setup-form">
                @csrf
                @method('PUT')

                <x-field name="name" :label="__('Business name')" :placeholder="$tenant->name" autocomplete="organization" dir="auto" maxlength="255" required />

                @if ($detected)
                    {{-- Open to show a mistake about the country, and open while a pick other than the current country waits (a page that came back for another reason must not hide it). --}}
                    <details class="setup-place" data-source="{{ $source }}" @if ($errors->has('country') || $picked !== $country) open @endif>
                        <summary>
                            <x-icon name="globe" :size="20" class="setup-globe" />
                            <span class="setup-where">
                                <span>{{ __('We think you are in :country: :currency, :zone.', [
                                    'country' => $countries[$country],
                                    'currency' => (string) $tenant->currency,
                                    'zone' => \App\Support\Countries::timeZoneName($tenant->localTimezone()),
                                ]) }}</span>
                                @if ($source === 'timezone')
                                    <span class="setup-guess">{{ __('Guessed from your device’s time zone, so it may be wrong.') }}</span>
                                @elseif ($source === 'language')
                                    <span class="setup-guess">{{ __('Guessed from your browser’s language, so it may be wrong.') }}</span>
                                @endif
                            </span>
                            <span class="setup-change">{{ __('Change') }} <x-icon name="chevronDown" :size="16" /></span>
                        </summary>
                        <div class="setup-chooser">
                            <div class="field">
                                <label for="setup-country">{{ __('Country') }}</label>
                                <select id="setup-country" name="country" autocomplete="country" @error('country') aria-invalid="true" aria-describedby="setup-country-hint setup-country-error" @else aria-describedby="setup-country-hint" @enderror>
                                    @foreach ($countries as $code => $label)
                                        <option value="{{ $code }}" @selected($picked === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="field-hint" id="setup-country-hint">{{ __('The currency and time zone follow the country.') }}</p>
                                @error('country')<p class="field-error" id="setup-country-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </details>
                @elseif ($open)
                    <div class="setup-place setup-ask">
                        <p class="setup-where">
                            <x-icon name="globe" :size="20" class="setup-globe" />
                            <span>{{ __('We could not tell where you are. Choose your country: its currency and time zone follow.') }}</span>
                        </p>
                        <div class="field">
                            <label for="setup-country">{{ __('Country') }}</label>
                            <select id="setup-country" name="country" autocomplete="country" @required(! $listed) @error('country') aria-invalid="true" aria-describedby="setup-country-error" @enderror>
                                @unless ($listed)
                                    <option value="" disabled @selected($picked === '')>{{ __('Select your country') }}</option>
                                @endunless
                                @foreach ($countries as $code => $label)
                                    <option value="{{ $code }}" @selected($picked === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('country')<p class="field-error" id="setup-country-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @else
                    {{-- The place is fixed (it decides the currency of every amount and the day every payment falls on). --}}
                    @error('country')
                        <p class="field-error" role="alert">{{ $message }}</p>
                    @else
                        <p class="setup-locked">
                            <x-icon name="lock" :size="18" />
                            <span>{{ __('The country can no longer be changed because this business already has contracts or payments. Contact support to change it.') }}</span>
                        </p>
                    @enderror
                @endif

                <div class="setup-actions">
                    <x-button>{{ __('Save and continue') }}</x-button>
                </div>
            </form>
        </section>
    @else
        <aside class="setup-notice" role="note">
            <x-icon name="clock" :size="18" />
            <p>{{ __('Your business owner needs to name the business before documents can be printed.') }}</p>
        </aside>
    @endif
@endunless
