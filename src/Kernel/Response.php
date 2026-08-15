<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * One response — accumulates headers/cookies and sends them all at the end
 *
 * Built as an object instead of echoing directly, so middleware can layer security
 * headers on top of it, and so tests can be written without an output buffer.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    /**
     * Produces the body in pieces — null means an ordinary response whose whole body
     * already sits in `$body`
     *
     * Exists for files too large to hold in memory all at once — see {@see stream()}
     *
     * @var (callable(callable(string):void):void)|null
     */
    private $producer = null;

    private function __construct(
        private string $body,
        private int $status,
        string $contentType,
    ) {
        $this->headers['Content-Type'] = $contentType;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=UTF-8');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /*
         * A JSON response is **live state** of the machine — the browser must never
         * be allowed to reuse a cached copy.
         *
         * A GET with no header saying otherwise can be cached by the browser on its
         * own judgement (heuristic caching). Our URLs are constant on every call —
         * `/api/v2/metrics/history?range=24h`, say, which the server page calls
         * again every minute — so **the graph would freeze at whatever time the
         * first successful load happened**, forever, even though the collector
         * writes a new row every minute and the API answers with fresh data every
         * time (found on the real server on 2026-08-14 — a graph stuck at the same
         * time for hours).
         *
         * Set here in one place because it's true of every API endpoint, not only
         * metrics — a caller that genuinely wants caching can override it with
         * `withHeader()`.
         */
        return (new self($json === false ? '{}' : $json, $status, 'application/json; charset=UTF-8'))
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=UTF-8');
    }

    /**
     * A response sent out piece by piece — for a file too large to hold in memory whole
     *
     * `$producer` receives an `emit(string $chunk)` function and may call it as many
     * times as it likes. Every piece is flushed out immediately, so the browser can
     * start saving the file from the very first chunk, and PHP's memory use doesn't
     * grow with the file's size.
     *
     * **Sends no `Content-Length`**, because the producer may not know the total
     * size at the start — a caller that does know the exact size can set it with
     * `withHeader()`, and the browser will then show a progress bar.
     *
     * `body()` can still return the whole thing (assembled on request), so a test
     * inspecting a response never has to know whether that endpoint streams or not.
     *
     * @param callable(callable(string):void):void $producer
     */
    public static function stream(callable $producer, string $contentType = 'application/octet-stream'): self
    {
        $response = new self('', 200, $contentType);
        $response->producer = $producer;

        return $response;
    }

    public static function redirect(string $location, int $status = 303): self
    {
        $response = new self('', $status, 'text/html; charset=UTF-8');

        // Guards against an open redirect: only an in-system path is accepted
        $response->headers['Location'] = str_starts_with($location, '/') && !str_starts_with($location, '//')
            ? $location
            : '/';

        return $response;
    }

    public static function noContent(): self
    {
        return new self('', 204, 'text/plain; charset=UTF-8');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,mixed> $options */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        if ($this->producer === null) {
            return $this->body;
        }

        // Only assembled whole when someone actually asks (tests) — the real send path never goes here
        $collected = '';
        ($this->producer)(static function (string $chunk) use (&$collected): void {
            $collected .= $chunk;
        });

        return $collected;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        // No Content-Length sent, since the web server may still compress the body
        if ($this->producer === null) {
            echo $this->body;

            return;
        }

        /*
         * Sends piece by piece, flushing after every one.
         *
         * Skip the flush and PHP buffers the whole file in memory anyway, which
         * defeats the entire point of this path. `ob_flush()` is only called when a
         * buffer actually exists — calling it with none raises a PHP notice on every
         * single chunk.
         */
        ($this->producer)(static function (string $chunk): void {
            echo $chunk;

            if (ob_get_level() > 0) {
                @ob_flush();
            }

            flush();
        });
    }
}
