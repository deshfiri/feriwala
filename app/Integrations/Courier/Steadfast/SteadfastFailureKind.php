<?php

namespace App\Integrations\Courier\Steadfast;

/**
 * Why a Steadfast call did not give an answer we can use.
 *
 * Kept apart because staff act differently on each: fix a key, wait and retry,
 * or correct what was sent. The message is translated, fixed text -- never
 * Steadfast's own words, which could carry customer data or echo a key.
 */
enum SteadfastFailureKind: string
{
    case NotConfigured = 'not_configured';
    case Authentication = 'authentication';
    case Unreachable = 'unreachable';
    case Timeout = 'timeout';
    case Validation = 'validation';
    case Unexpected = 'unexpected';

    public function message(): string
    {
        return __("steadfast.failure.{$this->value}");
    }

    /**
     * Whether the request may have reached Steadfast. Only a timeout may have:
     * a refused connection or a rejected key never got as far as acting.
     */
    public function outcomeIsUnknown(): bool
    {
        return $this === self::Timeout || $this === self::Unexpected;
    }
}
