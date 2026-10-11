<?php

namespace App\Domain\ExternalStore\Exceptions;

use RuntimeException;

/**
 * A store connection the rules do not allow (D29).
 *
 * Carries the form field it belongs to, so the controller reports it where the
 * partner is looking. Messages never name another account or repeat a secret.
 */
class ExternalStoreRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function notEntitled(): self
    {
        return new self(__('stores.refused.not_entitled'), 'store');
    }

    public static function limitReached(int $limit): self
    {
        return new self(__('stores.refused.limit_reached', ['limit' => $limit]), 'store');
    }

    public static function addressInvalid(): self
    {
        return new self(__('stores.refused.address_invalid'), 'base_url');
    }

    /**
     * Connected to a live Feriwala account already — this one or another.
     * Which one is not said.
     */
    public static function alreadyConnected(): self
    {
        return new self(__('stores.refused.already_connected'), 'base_url');
    }

    public static function verificationFailed(string $reason): self
    {
        return new self(__('stores.refused.verification_failed', ['reason' => $reason]), 'connection');
    }

    public static function credentialsRequired(): self
    {
        return new self(__('stores.refused.credentials_required'), 'api_key');
    }

    public static function notLive(): self
    {
        return new self(__('stores.refused.not_live'), 'store');
    }
}
