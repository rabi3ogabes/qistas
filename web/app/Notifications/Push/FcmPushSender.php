<?php

namespace App\Notifications\Push;

use App\Models\PushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1. The project's service account signs a short-lived request for an access token
 * (kept until a minute before it expires); each push is then one message to one phone. A phone Firebase reports gone is
 * revoked so it is never tried again; any other failure leaves it for the next alert.
 */
final class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Firebase's words for "this phone no longer has the app, or this token was never ours". */
    private const GONE = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    /** @param  array<string, mixed>  $account  the service account file Google gives (project_id, client_email, private_key, token_uri) */
    public function __construct(private readonly array $account) {}

    public function send(PushToken $token, PushMessage $message): bool
    {
        try {
            $response = Http::withToken($this->accessToken())->acceptJson()->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token->token,
                        'notification' => ['title' => $message->title, 'body' => $message->body],
                        'data' => ['type' => $message->type] + $message->data,
                        'android' => ['priority' => 'high'],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('push failed', ['type' => $message->type, 'error' => $e::class]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        // Firebase names the reason in error.details[].errorCode.
        $details = $response->json('error.details');
        $codes = [];
        foreach (is_array($details) ? $details : [] as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                $codes[] = $detail['errorCode'];
            }
        }
        if ($response->status() === 404 || array_intersect($codes, self::GONE) !== []) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
        Log::warning('push refused', ['type' => $message->type, 'status' => $response->status(), 'codes' => $codes]);

        return false;
    }

    private function accessToken(): string
    {
        $key = 'fcm:access-token:'.sha1((string) $this->account['client_email']);

        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $response = Http::asForm()->timeout(10)->post((string) ($this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token'), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion(),
        ]);
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            throw new RuntimeException('Firebase did not give an access token.');
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /** A JSON Web Token signed with the service account's key (RS256), as Google's token endpoint asks. */
    private function assertion(): string
    {
        $now = time();
        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        if (! openssl_sign($unsigned, $signature, (string) $this->account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Firebase key could not sign.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
