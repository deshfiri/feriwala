<?php

namespace App\Integrations\Sms\Contracts;

use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;

/**
 * One SMS provider (§30.1).
 *
 * BulkSMSBD, Nova SMS, SSLCommerz SMS, and Twilio all implement this, and §30.1
 * requires the architecture accept more. Adding a provider is one class plus a
 * config entry — no core change.
 */
interface SmsProvider
{
    /**
     * Send one message.
     *
     * Implementations must not throw for a provider-side rejection — an invalid
     * number or an exhausted balance is a result, not an exception, and the
     * queue should record it rather than retry it forever.
     */
    public function send(SmsMessage $message): SmsResult;

    /**
     * Remaining provider balance, where the provider exposes it (§30.2).
     */
    public function balance(): ?string;

    /**
     * Identifier used in config and logs.
     */
    public function name(): string;
}
