<?php

namespace App\Integrations\Store\Exceptions;

use RuntimeException;

/**
 * A store could not be reached or refused the call (D29).
 *
 * The message is always safe to store and to show the partner: it is chosen
 * here from what went wrong, never copied from the store's response, a header
 * or the request, so it cannot carry a credential or somebody's data.
 */
class StorePlatformException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self(__('stores.errors.unreachable'));
    }

    public static function credentialsRefused(int $status): self
    {
        return new self(__('stores.errors.credentials_refused'), $status, retryable: false);
    }

    public static function notFound(): self
    {
        return new self(__('stores.errors.api_not_found'), 404, retryable: false);
    }

    public static function rateLimited(): self
    {
        return new self(__('stores.errors.rate_limited'), 429);
    }

    public static function failed(int $status): self
    {
        return new self(__('stores.errors.store_error', ['status' => $status]), $status);
    }

    public static function unreadable(): self
    {
        return new self(__('stores.errors.unreadable'));
    }

    public static function addressRefused(): self
    {
        return new self(__('stores.errors.address_refused'), retryable: false);
    }
}
