<x-layouts.site :title="__('Pricing')" path="/pricing" :description="__('Start free, upgrade when you need more. See exactly what each Qistas plan includes.')">
    <section class="section">
        <div class="container">
            <h1 class="display" style="margin:0;font-size:clamp(2.5rem,6vw,4rem);max-width:16ch">{{ __('Simple pricing, nothing hidden.') }}</h1>
            <p class="section-lead">{{ __('Start free with no time limit. Upgrade when you outgrow it, and go back whenever you like: your records are never deleted.') }}</p>

            <div style="margin-top:2.5rem">
                @include('site.partials.plans', ['interactive' => true])
            </div>

            <div class="card compare table-wrap">
                <table class="table">
                    <caption class="sr-only">{{ __('What each plan includes') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Feature') }}</th>
                            @foreach ($offers as $offer)<th scope="col">{{ $offer->plan->name }}</th>@endforeach
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
        </div>
    </section>
</x-layouts.site>
