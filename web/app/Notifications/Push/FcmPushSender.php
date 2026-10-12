<?php

namespace App\Notifications\Push;

use App\Models\PushToken;
use App\Support\GoogleAccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1, as the project's service account (App\Support\GoogleAccessToken); each push is
 * one message to one phone. A phone Firebase reports gone is revoked so it is never tried again; any other failure
 * leaves it for the next alert.
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
            $response = Http::withToken(GoogleAccessToken::for($this->account, self::SCOPE))->acceptJson()->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token->token,
                        'notification' => ['title' => $message->title, 'body' => $message->body],
                        'data' => ['type' => $message->type] + $message->data,
                        'android' => ['priority' => 'high'],
                        // An iPhone shows the banner silently unless the push names a sound.
                        'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
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
}
