<?php

namespace App\Integrations\Store\Data;

use SensitiveParameter;

/**
 * Where a store's API lives, and the credentials to call it with (D29).
 *
 * The secret is marked sensitive so a stack trace never prints it, and it is
 * left out of debug output.
 */
final readonly class StoreEndpoint
{
    public function __construct(
        public string $baseUrl,
        #[SensitiveParameter] public string $apiKey,
        #[SensitiveParameter] public string $apiSecret,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[hidden]', 'apiSecret' => '[hidden]'];
    }
}
