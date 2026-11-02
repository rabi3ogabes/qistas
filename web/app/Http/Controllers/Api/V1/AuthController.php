<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Auth\SecondFactor;
use App\Auth\SignInLog;
use App\Http\Api\DeviceSession;
use App\Http\ApiException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Creating an account, signing in and out. Every sign-in gives the device its own token, which can be revoked, and goes
 * into the person's sign-in history on the app channel (with what the app reports about its device). A wrong password
 * for an existing account is recorded by the password check itself (AuthenticateUser).
 */
final class AuthController
{
    public function __construct(private readonly SignInLog $signIns) {}

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
            $user = $authenticate($request, 'app');
        } catch (ValidationException $e) {
            // The only thing the credentials check refuses with a message is a suspension, and it only says so to
            // someone who proved the password.
            throw new ApiException('account_suspended', collect($e->errors())->flatten()->first(), 403);
        }

        if ($user === null) {
            throw new ApiException('invalid_credentials', trans('auth.failed'), 401);
        }

        app(SecondFactor::class)->verify($user, $request->input('code'), $request->input('recovery_code'));

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

    /** The device's token, and with it the sign-in record: the sign-in or sign-up is complete once the app holds one. */
    private function session(User $user, Request $request, int $status): JsonResponse
    {
        $response = DeviceSession::issue($user, $request, $status);

        $this->signIns->success($user, $request, channel: 'app');

        return $response;
    }
}
