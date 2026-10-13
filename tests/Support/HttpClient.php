<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use CURLFile;
use RuntimeException;

/**
 * A tiny browser for the HTTP end-to-end suite, built on cURL.
 *
 *  - Each instance has its own cookie jar (one per simulated visitor), and
 *    sends/stores cookies only for its own origin.
 *  - Redirects are never followed automatically: tests assert the status and
 *    Location header, then call follow().
 *  - The CSRF token of the latest HTML page is remembered (csrfToken()), and
 *    submitForm() posts a form exactly as rendered, hidden _token included.
 */
final class HttpClient
{
    public readonly string $baseUrl;
    private readonly string $origin;

    /** @var array<string, string> */
    private array $cookies = [];
    private ?string $csrfToken = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->origin = self::originOf($this->baseUrl);
    }

    /**
     * Absolute URL for a page path ("cart.php"), a root path ("/x") or an
     * absolute URL. Fragments are dropped, as browsers never send them.
     */
    public function url(string $target): string
    {
        $target = (string) preg_replace('/#.*$/s', '', $target);
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }
        if (str_starts_with($target, '/')) {
            return $this->origin . $target;
        }
        return $this->baseUrl . '/' . $target;
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $target, array $headers = []): HttpResponse
    {
        return $this->request('GET', $target, null, $headers);
    }

    /**
     * Form-encoded POST (multipart when $files is given) or a raw string body.
     *
     * @param array<string, scalar>|string $body
     * @param array<string, string> $headers
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    public function post(string $target, array|string $body = [], array $headers = [], array $files = []): HttpResponse
    {
        return $this->request('POST', $target, $body, $headers, $files);
    }

    /**
     * POSTs $fields plus this visitor's current CSRF token.
     *
     * @param array<string, scalar> $fields
     */
    public function postWithToken(string $target, array $fields = []): HttpResponse
    {
        return $this->post($target, ['_token' => $this->csrfToken()] + $fields);
    }

    /**
     * Requests the Location of a redirect response with GET.
     */
    public function follow(HttpResponse $redirect): HttpResponse
    {
        $location = $redirect->header('Location');
        if (!$redirect->isRedirect() || $location === null) {
            throw new RuntimeException("Not a redirect:\n" . $redirect->describe());
        }
        return $this->get($location);
    }

    /**
     * Submits a form of $page the way a browser would: its own fields
     * (including the hidden CSRF token) overridden by $values.
     *
     * @param array<string, scalar> $values
     * @param array<string, string> $match Field values that identify the form among several with the same action
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    public function submitForm(HttpResponse $page, string $action, array $values = [], array $match = [], array $files = []): HttpResponse
    {
        $form = $page->document()->form($action, $match);
        $fields = array_map('strval', $values) + $form->fields;
        $target = $form->action === '' ? $page->url : $form->action;

        if ($form->method === 'GET') {
            $url = $this->url($target);
            $url = (string) preg_replace('/\?.*$/s', '', $url);
            return $this->get($url . '?' . http_build_query($fields));
        }
        return $this->request($form->method, $target, $fields, [], $files);
    }

    /**
     * The CSRF token of the most recent HTML page from this site; loads a
     * page first when none has been seen yet.
     */
    public function csrfToken(): string
    {
        if ($this->csrfToken === null) {
            $this->get('About_us.php');
        }
        if ($this->csrfToken === null) {
            throw new RuntimeException('Could not obtain a CSRF token from About_us.php.');
        }
        return $this->csrfToken;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * @param array<string, scalar>|string|null $body
     * @param array<string, string> $headers
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    public function request(string $method, string $target, array|string|null $body = null, array $headers = [], array $files = []): HttpResponse
    {
        $method = strtoupper($method);
        $url = $this->url($target);
        $sameOrigin = self::originOf($url) === $this->origin;

        // An empty Expect header stops cURL from waiting for "100 Continue".
        $headerLines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($sameOrigin && $this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $rawHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROXY => '',      // never route test traffic through a proxy from the environment
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rawHeaders): int {
                $rawHeaders[] = $line;
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($files !== []) {
            $fields = is_array($body) ? array_map('strval', $body) : [];
            foreach ($files as $field => $file) {
                $fields[$field] = new CURLFile($file['path'], $file['type'], $file['filename']);
            }
            $options[CURLOPT_POSTFIELDS] = $fields; // multipart/form-data
        } elseif (is_array($body)) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                $options[CURLOPT_POSTFIELDS] = http_build_query($body);
                $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $options[CURLOPT_HTTPHEADER] = $headerLines;

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException("Could not initialise cURL for $url");
        }
        curl_setopt_array($handle, $options);
        $content = curl_exec($handle);
        if ($content === false) {
            throw new RuntimeException("$method $url failed: " . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        $response = new HttpResponse($method, $url, $status, self::parseHeaders($rawHeaders), (string) $content);
        if ($sameOrigin) {
            if ($this->storeCookies($response)) {
                // A new session (login, logout) comes with a new CSRF token.
                $this->csrfToken = null;
            }
            if ($response->isHtml() && preg_match('/<meta name="csrf-token" content="([^"]*)"/', $response->body, $match)) {
                $this->csrfToken = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $response;
    }

    /**
     * @param list<string> $lines
     * @return array<string, list<string>>
     */
    private static function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if (str_starts_with($line, 'HTTP/')) {
                $headers = []; // a new (final) response after an interim one
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }
        return $headers;
    }

    /**
     * Applies Set-Cookie headers; returns true when any cookie changed.
     */
    private function storeCookies(HttpResponse $response): bool
    {
        $before = $this->cookies;
        foreach ($response->headerValues('Set-Cookie') as $line) {
            $parts = explode(';', $line);
            $pair = explode('=', (string) array_shift($parts), 2);
            $name = trim($pair[0]);
            $value = trim($pair[1] ?? '');
            if ($name === '') {
                continue;
            }

            $expired = $value === '' || $value === 'deleted';
            foreach ($parts as $attribute) {
                $attributePair = explode('=', $attribute, 2);
                $key = strtolower(trim($attributePair[0]));
                $attributeValue = trim($attributePair[1] ?? '');
                if ($key === 'max-age' && (int) $attributeValue <= 0) {
                    $expired = true;
                }
                if ($key === 'expires') {
                    $time = strtotime($attributeValue);
                    if ($time !== false && $time < time()) {
                        $expired = true;
                    }
                }
            }

            if ($expired) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        return $this->cookies !== $before;
    }

    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
