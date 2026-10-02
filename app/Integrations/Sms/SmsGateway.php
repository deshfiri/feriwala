<?php

namespace App\Integrations\Sms;

use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;

/**
 * What every credentialed SMS driver gets for free, and what it must still
 * answer itself -- the SMS mirror of
 * {@see Gateway}.
 *
 * A provider written but not yet confirmed against its own documentation
 * declares no driver in config at all (there is no capability flag to turn
 * off for SMS the way there is for payment gateways) -- so reaching this
 * base class at all means the protocol is meant to be live.
 */
abstract class SmsGateway implements SmsProvider
{
    /**
     * This provider's credentials.
     *
     * Every answer about naming and configuration comes from here, so a
     * driver cannot end up reporting one provider's name while reading
     * another's settings.
     */
    abstract protected function credentials(): SmsCredentials;

    public function name(): string
    {
        return $this->credentials()->provider();
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfiguration(): array
    {
        return $this->credentials()->requiredKeys();
    }

    /**
     * @return array<int, string>
     */
    public function missingConfiguration(): array
    {
        return $this->credentials()->missing();
    }

    /**
     * Expressed through {@see missingConfiguration()} so the two can never
     * disagree.
     */
    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    /**
     * Refuse before dialling out if anything is missing.
     *
     * @throws SmsProviderUnavailable
     */
    protected function requireConfigured(): void
    {
        if ($this->missingConfiguration() !== []) {
            throw SmsProviderUnavailable::missingCredentials($this->name());
        }
    }
}
