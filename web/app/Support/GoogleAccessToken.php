<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A short-lived Google API access token for a service account: the account signs a request (an RS256 JSON Web Token)
 * and Google's token endpoint answers with a token, kept until a minute before it expires. Shared by Firebase pushes
 * and Google Play billing.
 */
final class GoogleAccessToken
{
    /**
     * @param  array<string, mixed>  $account  the service account file Google gives (client_email, private_key, token_uri)
     *
     * @throws RuntimeException when Google gives no token
     */
    public static function for(array $account, string $scope): string
    {
        $key = 'google:access-token:'.sha1($scope.'|'.$account['client_email']);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $tokenUri = (string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => self::assertion($account, $scope, $tokenUri),
        ]);
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            throw new RuntimeException('Google did not give an access token.');
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /** @param  array<string, mixed>  $account */
    private static function assertion(array $account, string $scope, string $audience): string
    {
        $now = time();
        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $account['client_email'],
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        if (! openssl_sign($unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account key could not sign.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
