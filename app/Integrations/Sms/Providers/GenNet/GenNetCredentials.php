<?php

namespace App\Integrations\Sms\Providers\GenNet;

use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;
use App\Integrations\Sms\SmsCredentials;

/**
 * GenNet's account details, held encrypted in settings like every provider's.
 *
 * The base address is the one GenNet gives the account — it is never defaulted
 * or guessed here. It must be an https address: the API token travels in every
 * request, and over plain http it would travel in the clear.
 */
class GenNetCredentials extends SmsCredentials
{
    public function provider(): string
    {
        return 'gennet';
    }

    /**
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return ['base_url', 'api_token', 'sid'];
    }

    /**
     * A base address that is not a usable https address counts as missing, so
     * the provider cannot be chosen or sent through while it is wrong.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $missing = parent::missing();

        if (! in_array('base_url', $missing, true) && $this->baseUrl() === null) {
            $missing[] = 'base_url';
        }

        return $missing;
    }

    /**
     * The API's base address without a trailing slash, or null when what is
     * stored is not an https address.
     */
    public function baseUrl(): ?string
    {
        $value = $this->value('base_url');

        if ($value === null) {
            return null;
        }

        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return $value;
    }

    /**
     * @throws SmsProviderUnavailable
     */
    public function requireBaseUrl(): string
    {
        return $this->baseUrl() ?? throw SmsProviderUnavailable::missingCredentials($this->provider());
    }
}
