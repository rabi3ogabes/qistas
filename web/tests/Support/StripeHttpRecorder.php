<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Stands in for Stripe's HTTP layer in tests of the live gateway: answers each request from canned JSON chosen by method
 * and path, and keeps every request (method, path, params, headers) so a test can check exactly what was sent.
 */
final class StripeHttpRecorder implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: list<string>}> */
    public array $requests = [];

    /** @var list<array{method: string, pattern: string, status: int, body: array<string, mixed>|Throwable, once: bool, used: bool}> */
    private array $routes = [];

    /** Answer $method $path (a regex on the path, without delimiters) with $body; the first matching route wins. */
    public function on(string $method, string $pathPattern, array|Throwable $body, int $status = 200, bool $once = false): self
    {
        $this->routes[] = ['method' => strtolower($method), 'pattern' => $pathPattern, 'status' => $status, 'body' => $body, 'once' => $once, 'used' => false];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtolower($method), 'path' => $path, 'params' => $params ?? [], 'headers' => $headers];

        foreach ($this->routes as $i => $route) {
            if ($route['method'] !== strtolower($method) || ($route['once'] && $route['used']) || preg_match('#^'.$route['pattern'].'$#', $path) !== 1) {
                continue;
            }
            $this->routes[$i]['used'] = true;
            if ($route['body'] instanceof Throwable) {
                throw $route['body'];
            }

            return [(string) json_encode($route['body']), $route['status'], []];
        }

        throw new RuntimeException("No canned Stripe answer for {$method} {$path}");
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>, headers: list<string>}> */
    public function sent(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->requests, fn (array $r) => $r['method'] === strtolower($method) && preg_match('#^'.$pathPattern.'$#', $r['path']) === 1));
    }

    /** The Idempotency-Key header of a request, or null. */
    public static function idempotencyKey(array $request): ?string
    {
        foreach ($request['headers'] as $header) {
            if (stripos($header, 'Idempotency-Key:') === 0) {
                return trim(substr($header, strlen('Idempotency-Key:')));
            }
        }

        return null;
    }
}
