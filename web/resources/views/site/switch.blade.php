{{-- For a shop moving to Qistas from a notebook, a spreadsheet or another app (Sprint 2, D10): how the move goes, in order. --}}
@php use App\Site\SitePages; @endphp
<x-layouts.site :title="$guide['title']" :description="$guide['lead']" :path="SitePages::PAGES['switch.'.$from]">
    <section class="hero switch-hero">
        <div class="container">
            <h1 class="display">{{ $guide['title'] }}</h1>
            <p class="hero-lead">{{ $guide['lead'] }}</p>
            <div class="hero-actions">
                <a class="btn btn-gold btn-lg" href="{{ route('register') }}">{{ __('Start free') }}</a>
                <a class="btn btn-on-dark btn-lg" href="{{ SitePages::url('pricing') }}">{{ __('See pricing') }}</a>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="switch-steps-title">
        <div class="container">
            <h2 id="switch-steps-title" class="display">{{ __('How the move goes') }}</h2>
            <ol class="switch-steps">
                @foreach ($guide['steps'] as [$step, $detail])
                    <li>
                        <h3>{{ $step }}</h3>
                        <p>{{ $detail }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="section section-alt">
        <div class="container">
            @include('site.partials.principles')
        </div>
    </section>

    <section class="cta-band">
        <div class="container cta-band-inner">
            <h2 class="display">{{ __('Open your free workspace.') }}</h2>
            <p>{{ __('It takes a minute. No card needed.') }}</p>
            <a class="btn btn-gold btn-lg" href="{{ route('register') }}">{{ __('Start free') }}</a>
        </div>
    </section>
</x-layouts.site>
