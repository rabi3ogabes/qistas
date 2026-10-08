<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Http\Api\AccountPayload;
use App\Http\ApiException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/** Creating an account, signing in and out. Every sign-in gives the device its own token, which can be revoked. */
final class AuthController
{
    public function register(Request $request, CreateNewUser $create): JsonResponse
    {
        $request->validate(['device_name' => ['nullable', 'string', 'max:100']]);

        $user = $create->create($request->all());
        event(new Registered($user));

        return $this->session($user, $request, 201);
    }

    public function login(Request $request, AuthenticateUser $authenticate): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $user = $authenticate($request);
        } catch (ValidationException $e) {
            // The only thing the credentials check refuses with a message is a suspension, and it only says so to
            // someone who proved the password.
            throw new ApiException('account_suspended', collect($e->errors())->flatten()->first(), 403);
        }

        if ($user === null) {
            throw new ApiException('invalid_credentials', trans('auth.failed'), 401);
        }

        if ($user->hasConfirmedTwoFactor()) {
            $this->secondFactor($user, $request);
        }

        Audit::record('login.api', userId: $user->id);

        return $this->session($user, $request, 200);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** Ends every signed-in device; API access tokens made on purpose (for integrations) are left alone. */
    public function logoutAll(Request $request): Response
    {
        $request->user()->tokens()->where('abilities', 'like', '%"app"%')->delete();

        return response()->noContent();
    }

    private function session(User $user, Request $request, int $status): JsonResponse
    {
        $tenant = $user->primaryTenant() ?? throw new ApiException('no_workspace', __('This account does not belong to a workspace.'), 403);

        $token = $user->createToken(
            (string) ($request->input('device_name') ?: __('Mobile app')),
            ['app'],
            now()->addDays((int) config('qistas.security.api_token_days')),
        );

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ...AccountPayload::for($user, $tenant),
        ]], $status);
    }

    /** A person with two-factor authentication on must also give a code from their authenticator, or a recovery code. */
    private function secondFactor(User $user, Request $request): void
    {
        $code = trim((string) $request->input('code'));
        $recovery = trim((string) $request->input('recovery_code'));

        if ($code === '' && $recovery === '') {
            throw new ApiException('two_factor_required', __('Enter the code from your authenticator app.'), 422);
        }

        if ($code !== '' && app(TwoFactorAuthenticationProvider::class)->verify(decrypt($user->two_factor_secret), $code)) {
            return;
        }

        if ($recovery !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $stored) => hash_equals($stored, $recovery));

            if ($match !== null) {
                $user->replaceRecoveryCode($match);

                return;
            }
        }

        Audit::record('login.two_factor_failed', userId: $user->id);

        throw new ApiException('invalid_two_factor_code', __('That code is not valid.'), 401);
    }
}
