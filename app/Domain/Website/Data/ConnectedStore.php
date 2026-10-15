<?php

namespace App\Domain\Website\Data;

use App\Domain\Website\Models\WebsiteCredential;

/**
 * What connecting a store produced, shown once.
 *
 * The token is the credential's secret. It exists in this object only for the
 * length of the response that created it and is never stored, flashed or
 * logged; losing it means rotating.
 */
final class ConnectedStore
{
    public function __construct(
        public readonly WebsiteCredential $credential,
        public readonly string $token,
        public readonly string $address,
        public readonly bool $local,
        public readonly ?string $webhookSecret,
        public readonly ?string $webhookUrl,
        public readonly string $erpBaseUrl,
    ) {}

    public function storeId(): string
    {
        return $this->credential->key_id;
    }

    /**
     * The whole configuration the storefront needs, as `.env` lines: where the
     * ERP is, which store this is, and the token that proves it.
     */
    public function env(): string
    {
        $lines = [
            '# Feriwala storefront connection (server-side only; never commit this)',
            'ERP_BASE_URL='.$this->erpBaseUrl,
            'STORE_ID='.$this->storeId(),
            'STORE_TOKEN='.$this->token,
        ];

        if ($this->webhookSecret !== null) {
            $lines[] = 'WEBHOOK_SECRET='.$this->webhookSecret;
        }

        if ($this->local) {
            $lines[] = 'APP_ENV=local';
        }

        return implode("\n", $lines)."\n";
    }
}
