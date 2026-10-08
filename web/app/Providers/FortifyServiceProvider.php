<?php

namespace App\Providers;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One answer for "known email" and "unknown email" alike: the form must not reveal who has an account.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);
        Fortify::authenticateUsing(app(AuthenticateUser::class));

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        $this->rateLimiters();
    }

    private function rateLimiters(): void
    {
        // Per account+address stops guessing one account; per address stops trying many accounts.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(config('qistas.security.login_attempts_per_minute'))
                ->by(Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip())),
            Limit::perMinute(20)->by('login-address|'.$request->ip()),
        ]);

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by((string) ($request->session()->get('login.id') ?? $request->ip())));

        // The demo buttons make a real account each time: a few an hour per address, shared by web and app.
        RateLimiter::for('demo', fn (Request $request) => Limit::perHour(max(1, (int) config('qistas.demo_login.per_hour')))->by('demo|'.$request->ip()));
        RateLimiter::for('api-demo', fn (Request $request) => Limit::perHour(max(1, (int) config('qistas.demo_login.per_hour')))->by('demo|'.$request->ip()));

        // The public instalment calculator is cheap but unauthenticated: one address gets a minute's budget.
        RateLimiter::for('schedule-preview', fn (Request $request) => Limit::perMinute(60)->by('schedule-preview|'.$request->ip()));

        // The REST API (see routes/api.php). Signed-in calls are counted per person, the rest per address.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('api|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('api-money', fn (Request $request) => Limit::perMinute(60)->by('api-money|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('api-public', fn (Request $request) => Limit::perMinute(60)->by('api-public|'.$request->ip()));
        RateLimiter::for('api-register', fn (Request $request) => Limit::perMinute(5)->by('api-register|'.$request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(config('qistas.security.login_attempts_per_minute'))
                ->by('api-login|'.Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perMinute(20)->by('api-login-address|'.$request->ip()),
        ]);

        // The signed-in contract form asks for a fresh preview as the person types, so it gets a wider budget.
        RateLimiter::for('contract-preview', fn (Request $request) => Limit::perMinute(180)->by('contract-preview|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Applied to every Fortify route (config/fortify.php). Sign-up and reset requests are the expensive
        // or abusable ones, so they get a tight budget.
        RateLimiter::for('auth-forms', fn (Request $request) => match (true) {
            $request->isMethod('POST') && $request->routeIs('register.store') => Limit::perMinute(5)->by('register|'.$request->ip()),
            $request->isMethod('POST') && $request->routeIs('password.email') => Limit::perMinute(5)->by('password-email|'.$request->ip()),
            default => Limit::perMinute(120)->by('auth-forms|'.$request->ip()),
        });
    }
}
