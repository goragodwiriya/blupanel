<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * One request — wraps the superglobals in a single place
 *
 * No other code may touch $_GET/$_POST/$_SERVER directly, so there is one place that
 * controls how the real IP is resolved and how untrusted values are read.
 */
final class Request
{
    /** @param array<string,string> $params route parameters such as {id} */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $cookies,
        public readonly array $server,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $requestId,
        public array $params = [],
        /** Raw body that can be injected for testing — normally null, then read from php://input */
        private ?string $rawBody = null,
    ) {
    }

    /** @var array<string,mixed>|null the decoded JSON body, cached for reuse */
    private ?array $json = null;

    /**
     * Builds a Request from supplied values — used by REST API contract tests
     *
     * Exists so the whole pipeline (middleware + routing + controller) can be tested
     * in one process without starting a real web server, letting the contract tests
     * run in CI with no root access.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers header names exactly as written, e.g. 'X-CSRF-Token'
     * @param array<string,string> $cookies
     */
    public static function make(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $headers = [],
        array $cookies = [],
        ?string $rawBody = null,
        string $ip = '127.0.0.1',
    ): self {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => $ip];

        // Places headers into $_SERVER the same way PHP-FPM really does — Content-Type
        // and Content-Length carry no HTTP_ prefix, per RFC 3875. If a test placed
        // them differently, it would walk a different code path than production and
        // let a bug like the one this class once had slip through again.
        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $server[in_array($normalized, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
                ? $normalized
                : 'HTTP_'.$normalized] = $value;
        }

        return new self(
            method: strtoupper($method),
            path: $path,
            query: $query,
            post: $post,
            files: [],
            cookies: $cookies,
            server: $server,
            ip: $ip,
            userAgent: $headers['User-Agent'] ?? 'phpcp-test',
            requestId: bin2hex(random_bytes(8)),
            rawBody: $rawBody,
        );
    }

    /**
     * @param Config $config
     */
    public static function capture(Config $config): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/'.trim($path, '/');

        return new self(
            method: $method,
            path: $path === '//' ? '/' : $path,
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            cookies: $_COOKIE,
            server: $server,
            ip: self::resolveIp($server, $config->list('panel.trusted_proxies')),
            userAgent: substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 512),
            requestId: bin2hex(random_bytes(8)),
        );
    }

    /**
     * Resolves the caller's real IP
     *
     * X-Forwarded-For can only be trusted when the request comes from a proxy we've
     * configured ourselves — otherwise an attacker can forge this header to dodge
     * rate limiting and IP allowlists outright.
     *
     * ## Why walk from the right, not just take the leftmost entry
     *
     * `X-Forwarded-For` is a list **each proxy appends to** as the request passes
     * through — so the leftmost entry is whatever the outermost layer received,
     * which is **whatever the user sent themselves**, if they attached this header
     * on their own. This code used to take the leftmost entry, meaning anyone
     * sending a request through our trusted proxy could simply attach
     * `X-Forwarded-For: 1.2.3.4` and instantly appear as 1.2.3.4 to the system —
     * dodging rate limits by changing the number on every request, and making the
     * session-to-IP binding ({@see \Phpcp\Security\SessionStore::find()}) meaningless,
     * since stealing a cookie would let someone declare the owner's IP themselves.
     *
     * The correct approach walks in from the **rightmost** entry, skipping over the
     * layers that are our own proxies, and stops at the first one that isn't — that
     * value is what our innermost proxy **saw with its own eyes**, not something
     * anyone could write in.
     *
     * A value in an unreadable shape is treated as entirely untrustworthy and falls
     * back to REMOTE_ADDR — skipping a malformed entry and reading the next one would
     * let an attacker choose which entry gets read.
     *
     * @param array<string,mixed> $server
     * @param list<string> $trustedProxies
     */
    private static function resolveIp(array $server, array $trustedProxies): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        $chain = explode(',', $forwarded);

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = trim($chain[$i]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }

            if (!in_array($candidate, $trustedProxies, true)) {
                return $candidate;
            }
        }

        // The whole chain is our own proxies — nobody outside the system is in here at all
        return $remote;
    }

    /**
     * @param array $params
     * @return mixed
     */
    /**
     * Route parameters are always strings — this enforces that too
     *
     * Because calling code often passes a number itself (`withParams(['id' => $id])`
     * when store() hands work off to update()), and param()/paramInt(), which expect
     * a string, would otherwise blow up mid-request with a TypeError.
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $params,
        );

        return $clone;
    }

    /**
     * @param string $key
     * @param string $default
     * @return mixed
     */
    public function param(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param int $default
     * @return mixed
     */
    public function paramInt(string $key, int $default = 0): int
    {
        $value = $this->params[$key] ?? null;

        if (!is_string($value)) {
            return is_int($value) ? $value : $default;
        }

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * @return mixed
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->lookup($this->query, $key);

        return $value !== null && is_scalar($value) ? (string) $value : $default;
    }

    /** A query-string value that must be numeric — used for the REST API's page/per_page */
    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * The JSON body sent with the request — an empty array if it isn't JSON
     *
     * Needed for the REST API because PHP only populates `$_POST` for form-encoded
     * bodies. A `PATCH`/`PUT` request sending `application/json` always gets an empty `$_POST`.
     *
     * `php://input` is read once and cached — that stream can't be read twice on some SAPIs.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        if (!str_contains(strtolower($this->header('Content-Type')), 'application/json')) {
            return $this->json = [];
        }

        $raw = $this->rawBody ?? (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        return $this->json = is_array($decoded) ? $decoded : [];
    }

    /** true = Content-Type: application/json was sent but the body couldn't be parsed (400 BAD_REQUEST) */
    public function hasBrokenJson(): bool
    {
        if (!str_contains(strtolower($this->header('Content-Type')), 'application/json')) {
            return false;
        }

        $raw = $this->rawBody ?? (string) file_get_contents('php://input');

        return trim($raw) !== '' && !is_array(json_decode($raw, true));
    }

    /**
     * A value the caller sent, whether through a JSON body or a form
     *
     * The REST API uses this instead of input() so both the SPA (JSON) and testing
     * with `curl -d` (form-encoded) work without writing two code paths — phase B's
     * acceptance criteria require "every resource callable entirely with curl,
     * without ever opening a browser".
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key);

        return $value ?? $default;
    }

    public function payloadString(string $key, string $default = ''): string
    {
        $value = $this->payload($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Is this path under REST API v2 — used by middleware to decide the response shape */
    public function isApiV2(): bool
    {
        return $this->path === '/api/v2' || str_starts_with($this->path, '/api/v2/');
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key);

        return $value !== null && is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key) ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($value, is_scalar(...))));
    }

    /**
     * Reads a value from $_GET/$_POST, tolerating keys that contain a dot
     *
     * PHP turns `.` and spaces in a form field's name into `_` before placing it in
     * the superglobal, so name="notify.telegram.enabled" arrives as the key
     * notify_telegram_enabled — if the dotted key isn't found, the PHP-converted form
     * is tried next.
     *
     * @param array<string,mixed> $bag
     */
    private function lookup(array $bag, string $key): mixed
    {
        if (array_key_exists($key, $bag)) {
            return $bag[$key];
        }

        if (strpbrk($key, '. ') === false) {
            return null;
        }

        $alt = str_replace(['.', ' '], '_', $key);

        return array_key_exists($alt, $bag) ? $bag[$alt] : null;
    }

    /**
     * Files uploaded under one field — always returns a list, whether one file or several were sent
     *
     * PHP shapes $_FILES two different ways: a single field gets scalar values,
     * while a field named with a trailing [] gets parallel arrays. Combining both
     * shapes into one here means a controller never has to write both paths and risk
     * missing one.
     *
     * @return list<array{name:string,tmp_name:string,size:int,error:int}>
     */
    public function files(string $key): array
    {
        $entry = $this->files[$key] ?? null;
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }

        $names = is_array($entry['name']) ? $entry['name'] : [$entry['name']];
        $result = [];

        foreach (array_keys($names) as $index) {
            $pick = static fn(string $field): mixed => is_array($entry[$field] ?? null)
                ? ($entry[$field][$index] ?? null)
                : ($entry[$field] ?? null);

            $result[] = [
                // A name from the browser isn't trustworthy — the caller must always validate it before use
                'name' => (string) $pick('name'),
                'tmp_name' => (string) $pick('tmp_name'),
                'size' => (int) $pick('size'),
                'error' => (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE)
            ];
        }

        return $result;
    }

    /**
     * @param string $name
     * @param string $default
     */
    public function cookie(string $name, string $default = ''): string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param string $name
     * @param string $default
     */
    /**
     * The two headers CGI does not prefix with `HTTP_`
     *
     * Per RFC 3875 (CGI), `Content-Type` and `Content-Length` are passed through as
     * plain `CONTENT_TYPE` / `CONTENT_LENGTH` variables, not `HTTP_CONTENT_TYPE` like
     * every other header — PHP-FPM, mod_cgi, and FrankenPHP all follow this.
     *
     * @var array<string,string>
     */
    private const CGI_HEADERS = [
        'CONTENT_TYPE' => 'CONTENT_TYPE',
        'CONTENT_LENGTH' => 'CONTENT_LENGTH',
    ];

    /**
     * A request header's value
     *
     * **This was once a bug that broke the entire REST API v2 on the real server:**
     * this method looked only for `HTTP_CONTENT_TYPE`, which PHP-FPM never sets. The
     * result was `json()` seeing an empty Content-Type and never parsing the body —
     * every request sending JSON silently got an empty payload. Logging in through
     * the API didn't work, creating a site didn't work, with no error anywhere to
     * point at it.
     *
     * All 71 contract tests passed anyway, because `make()` set `HTTP_CONTENT_TYPE`
     * itself. `make()` now sets it the same way CGI does, so the tests walk the same
     * path production does.
     */
    public function header(string $name, string $default = ''): string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        $key = self::CGI_HEADERS[$normalized] ?? 'HTTP_'.$normalized;

        $value = $this->server[$key] ?? $this->server['HTTP_'.$normalized] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** true = the client wants JSON (called from fetch in JS) */
    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json')
        || $this->header('X-Requested-With') === 'fetch'
        || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
        || (int) ($this->server['SERVER_PORT'] ?? 0) === 443
        || $this->header('X-Forwarded-Proto') === 'https';
    }
}
