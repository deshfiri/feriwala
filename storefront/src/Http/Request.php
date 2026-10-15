<?php

declare(strict_types=1);

namespace Storefront\Http;

use Storefront\Support\Env;

final class Request
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @param  array<string, string>  $headers  lower-cased names
     * @param  array<string, mixed>  $server
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
    ) {}

    public static function capture(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/'.trim(rawurldecode($path), '/'),
            $_GET,
            $_POST,
            $headers,
            $_SERVER,
            $_COOKIE,
            (string) file_get_contents('php://input'),
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('accept'), 'application/json')
            || $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    /**
     * The client address. Forwarded headers are believed only when the operator
     * has said a proxy sits in front (`TRUSTED_PROXY=1`), never on their own.
     */
    public function ip(): string
    {
        if (Env::bool('TRUSTED_PROXY')) {
            $forwarded = $this->header('x-forwarded-for');

            if ($forwarded !== null) {
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off') {
            return true;
        }

        return Env::bool('TRUSTED_PROXY') && $this->header('x-forwarded-proto') === 'https';
    }
}
