<?php

namespace App\Billing\Store;

use App\Billing\BillingSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proof that a notification really comes from Google Cloud Pub/Sub for this endpoint. The push subscription is set up
 * with authentication: each push carries a token Google signs (RS256) naming the audience and the service account it
 * pushes as. Both must match what the super admin saved, the signature must check against one of Google's published
 * certificates, and the token must be current. Anything else is refused before the message is read.
 */
final class GooglePushAuth
{
    public const CERTIFICATES = 'https://www.googleapis.com/oauth2/v1/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Seconds of clock difference forgiven either way. */
    private const LEEWAY = 60;

    public function __construct(private readonly BillingSettings $settings) {}

    public function accepts(?string $bearer): bool
    {
        $audience = $this->settings->pushAudience();
        $email = $this->settings->pushEmail();
        $parts = explode('.', (string) $bearer);
        if ($audience === null || $email === null || count($parts) !== 3) {
            return false;
        }

        [$head, $body, $signature] = $parts;
        $header = json_decode((string) self::decode($head), true);
        $claims = json_decode((string) self::decode($body), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            return false;
        }

        $certificate = $this->certificate($header['kid']);
        if ($certificate === null || openssl_verify("{$head}.{$body}", (string) self::decode($signature), $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        // The real clock, never the application's: a token's lifetime is measured by Google's clock.
        $now = time();

        return in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && ($claims['aud'] ?? null) === $audience
            && ($claims['email'] ?? null) === $email
            && ($claims['email_verified'] ?? false) === true
            && is_int($claims['exp'] ?? null) && $claims['exp'] >= $now - self::LEEWAY
            && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + self::LEEWAY;
    }

    /** Google's certificate for a key id, kept for an hour; fetched again once if the key is new (Google rotates them). */
    private function certificate(string $kid): ?string
    {
        $certificates = Cache::get('google:push-certificates');
        if (! is_array($certificates) || ! isset($certificates[$kid])) {
            try {
                $certificates = Http::timeout(10)->get(self::CERTIFICATES)->throw()->json();
            } catch (Throwable) {
                return null;
            }
            if (! is_array($certificates)) {
                return null;
            }
            Cache::put('google:push-certificates', $certificates, 3600);
        }

        return is_string($certificates[$kid] ?? null) ? $certificates[$kid] : null;
    }

    private static function decode(string $part): string|false
    {
        return base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
    }
}
