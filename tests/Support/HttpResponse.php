<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * One HTTP response received by HttpClient (redirects are never followed).
 */
final class HttpResponse
{
    private ?HtmlDocument $document = null;

    /**
     * @param array<string, list<string>> $headers Header values by lower-case name
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * First value of a header, or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function contentType(): string
    {
        return strtolower((string) $this->header('Content-Type'));
    }

    public function isHtml(): bool
    {
        return str_starts_with($this->contentType(), 'text/html');
    }

    public function document(): HtmlDocument
    {
        return $this->document ??= new HtmlDocument($this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Response is not JSON ({$e->getMessage()}).\n" . $this->describe(), 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException("Response is not a JSON object.\n" . $this->describe());
        }
        return $data;
    }

    /**
     * Raw Set-Cookie header for a cookie name (the last one wins), or null.
     */
    public function setCookieHeader(string $cookieName): ?string
    {
        $found = null;
        foreach ($this->headerValues('Set-Cookie') as $line) {
            if (str_starts_with($line, $cookieName . '=')) {
                $found = $line;
            }
        }
        return $found;
    }

    /**
     * A short human-readable summary for assertion messages.
     */
    public function describe(int $limit = 1500): string
    {
        $summary = sprintf("%s %s -> %d", $this->method, $this->url, $this->status);
        if ($this->header('Location') !== null) {
            $summary .= "\nLocation: " . $this->header('Location');
        }
        if ($this->body !== '') {
            $text = $this->isHtml() ? $this->document()->text() : $this->body;
            $summary .= "\n" . mb_substr($text, 0, $limit) . (mb_strlen($text) > $limit ? ' [...]' : '');
        }
        return $summary;
    }
}
