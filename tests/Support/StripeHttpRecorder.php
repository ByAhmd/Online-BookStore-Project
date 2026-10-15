<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use Closure;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stripe HTTP client used by the integration tests. Requests go through the
 * real cURL client to the local mock router; this wrapper records what the
 * application sent (before form encoding, so integer types can be asserted)
 * and can inject one-off failures or rewrite a response.
 */
final class StripeHttpRecorder implements ClientInterface
{
    /** @var list<array{method: string, path: string, headers: list<string>, params: array<mixed>}> */
    private array $requests = [];

    /** @var list<array{method: string, pattern: string, respond: Closure(Closure(): array{0: string, 1: int, 2: mixed}): array{0: string, 1: int, 2: mixed}}> */
    private array $interceptors = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    /**
     * @param 'delete'|'get'|'post' $method
     * @param array<int, string> $headers
     * @param array<mixed> $params
     * @param bool $hasFile
     * @param 'v1'|'v2' $apiMode
     * @param int|null $maxNetworkRetries
     * @return array{0: string, 1: int, 2: mixed}
     */
    #[\Override]
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $verb = strtoupper($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $verb, 'path' => $path, 'headers' => array_values($headers), 'params' => $params];

        $forward = fn (): array => $this->inner->request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
        foreach ($this->interceptors as $index => $interceptor) {
            if ($interceptor['method'] === $verb && preg_match($interceptor['pattern'], $path) === 1) {
                array_splice($this->interceptors, $index, 1);
                return ($interceptor['respond'])($forward);
            }
        }
        return $forward();
    }

    /**
     * Makes the next matching request fail with an HTTP error from "Stripe".
     */
    public function failNext(string $method, string $pathPattern, int $status = 500, string $message = 'Injected Stripe failure'): void
    {
        $body = json_encode([
            'error' => [
                'type' => $status >= 500 ? 'api_error' : 'invalid_request_error',
                'code' => 'injected_failure',
                'message' => $message,
            ],
        ], JSON_THROW_ON_ERROR);
        $this->intercept($method, $pathPattern, static fn (): array => [
            $body,
            $status,
            new CaseInsensitiveArray(['request-id' => 'req_injected', 'stripe-should-retry' => 'false']),
        ]);
    }

    /**
     * Makes the next matching request fail as if Stripe were unreachable.
     */
    public function failNextWithConnectionError(string $method, string $pathPattern): void
    {
        $this->intercept($method, $pathPattern, static function (): never {
            throw ApiConnectionException::factory('Injected connection failure: could not reach Stripe');
        });
    }

    /**
     * Sends the next matching request to the mock, then rewrites its JSON response.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $rewrite
     */
    public function rewriteNextResponse(string $method, string $pathPattern, callable $rewrite): void
    {
        $this->intercept($method, $pathPattern, static function (Closure $forward) use ($rewrite): array {
            [$body, $status, $headers] = $forward();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return [json_encode($rewrite($decoded), JSON_THROW_ON_ERROR), $status, $headers];
        });
    }

    /**
     * Sends the next matching request to the mock and adds a Stripe-Notice
     * header to its response, as Stripe does for some requests in test mode.
     */
    public function noticeOnNextResponse(string $method, string $pathPattern, string $notice): void
    {
        $this->intercept($method, $pathPattern, static function (Closure $forward) use ($notice): array {
            [$body, $status, $headers] = $forward();
            $headers['Stripe-Notice'] = $notice;
            return [$body, $status, $headers];
        });
    }

    /**
     * Recorded requests, optionally filtered by method and a path regex.
     *
     * @return list<array{method: string, path: string, headers: list<string>, params: array<mixed>}>
     */
    public function requests(?string $method = null, ?string $pathPattern = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => ($method === null || $request['method'] === strtoupper($method))
                && ($pathPattern === null || preg_match($pathPattern, $request['path']) === 1)
        ));
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->interceptors = [];
    }

    /**
     * @param Closure(Closure(): array{0: string, 1: int, 2: mixed}): array{0: string, 1: int, 2: mixed} $respond
     */
    private function intercept(string $method, string $pathPattern, Closure $respond): void
    {
        $this->interceptors[] = ['method' => strtoupper($method), 'pattern' => $pathPattern, 'respond' => $respond];
    }
}
