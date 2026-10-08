<x-layouts.auth :title="__('Sign in')" :heading="__('Welcome back')" :lead="__('Sign in to manage your customers and instalments.')">
    @if (config('qistas.demo'))
        <aside class="demo-login" aria-label="{{ __('Demo account') }}">
            <p><strong>{{ __('Look around with the demo account') }}</strong></p>
            <dl>
                <div><dt>{{ __('Email') }}</dt><dd dir="ltr">{{ \Database\Seeders\DemoSeeder::EMAIL }}</dd></div>
                <div><dt>{{ __('Password') }}</dt><dd dir="ltr">{{ \Database\Seeders\DemoSeeder::PASSWORD }}</dd></div>
            </dl>
        </aside>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="form" novalidate>
        @csrf
        <x-field name="email" type="email" :label="__('Email')" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus />
        <x-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required />

        <div class="form-row">
            <label class="check"><input type="checkbox" name="remember" value="1"> <span>{{ __('Keep me signed in') }}</span></label>
            <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
        </div>

        <x-button>{{ __('Sign in') }}</x-button>
    </form>

    <p class="auth-alt">{{ __('New to :app?', ['app' => config('qistas.app_name')]) }} <a href="{{ route('register') }}">{{ __('Create a free account') }}</a></p>

    @if (\App\Sandbox\DemoAccess::enabled())
        <section class="demo-choices" aria-labelledby="demo-title">
            <h2 id="demo-title">{{ __('Just looking around?') }}</h2>
            <p>{{ __('Try the demo with sample data. Nothing to sign up for, nothing to type.') }}</p>
            <div class="demo-grid">
                @foreach (app(\App\Sandbox\DemoAccess::class)->personas() as $persona)
                    <form method="POST" action="{{ route('demo.start', $persona['key']) }}">
                        @csrf
                        <button type="submit" class="demo-choice" data-persona="{{ $persona['key'] }}">
                            <span class="demo-choice-title">{{ $persona['label'] }}</span>
                            <span class="demo-choice-note">{{ $persona['description'] }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.auth>
