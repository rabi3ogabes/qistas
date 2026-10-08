<x-layouts.app :title="__('Plan and billing')" section="billing">
    <x-page-head :title="__('Plan and billing')">
        <x-slot:subtitle>{{ __('What your workspace is on, and what the other plan adds.') }}</x-slot:subtitle>
    </x-page-head>

    <section class="card card-pad" aria-labelledby="your-plan">
        <h2 id="your-plan" class="checklist-title">{{ __('Your plan') }}: {{ $current->name }}</h2>
        <div class="usage">
            <x-meter :label="__('Customers')" :entitlement="$usage['customers']" />
            <x-meter :label="__('Active contracts')" :entitlement="$usage['contracts']" />
        </div>
    </section>

    @if ($current->isFree())
        <section class="card card-pad" aria-labelledby="upgrade" style="margin-top:1rem">
            <h2 id="upgrade" class="checklist-title">{{ __('Move to Pro') }}</h2>
            <p>{{ __('Online payment is not switched on yet. Until it is, the Qistas team turns Pro on for your workspace: write to us from the e-mail address you sign in with and say which workspace it is for.') }}</p>
            <p>
                <strong>{{ __('Workspace') }}:</strong> {{ $tenantName }}
                @if ($supportEmail)
                    <br><a class="btn btn-gold" style="margin-top:.75rem" href="mailto:{{ $supportEmail }}?subject={{ rawurlencode(__('Pro for :workspace', ['workspace' => $tenantName])) }}">{{ __('Ask for Pro') }}</a>
                @endif
            </p>
            <p class="field-hint">{{ __('Your records are never deleted if you go back to Free: they just stay in view while the Free limits apply to anything new.') }}</p>
        </section>
    @endif

    <div class="card compare table-wrap" style="margin-top:1rem">
        <table class="table">
            <caption class="sr-only">{{ __('What each plan includes') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Feature') }}</th>
                    @foreach ($offers as $offer)<th scope="col">{{ $offer->plan->name }}@if ($offer->plan->key === $current->key) <span class="badge">{{ __('Your plan') }}</span>@endif</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($features as $feature)
                    <tr>
                        <th scope="row">{{ $feature->label() }}</th>
                        @foreach ($offers as $offer)
                            @php($row = $offer->feature($feature))
                            <td>
                                <span class="cell {{ $row['enabled'] ? 'yes' : 'no' }}">
                                    @if ($feature->type() === \App\Entitlements\FeatureType::Toggle)
                                        <x-icon :name="$row['enabled'] ? 'check' : 'x'" :size="18" />
                                        <span class="sr-only">{{ $row['short'] }}</span>
                                    @else
                                        @if (! $row['enabled'])<x-icon name="x" :size="18" />@endif
                                        <span @class(['sr-only' => ! $row['enabled']])>{{ $row['short'] }}</span>
                                    @endif
                                </span>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
