<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Integrations\Store\Exceptions\StorePlatformException;
use App\Integrations\Store\StorePlatformManager;

/**
 * Ask a connected store whether it still answers (D29).
 *
 * Informational: it says what happened and moves nothing. Whether a connection
 * is failing is decided by syncs, which actually import orders.
 */
class TestExternalStoreConnection
{
    public function __construct(
        protected StorePlatformManager $platforms,
    ) {}

    /**
     * @return array{ok: bool, message: string|null}
     *
     * @throws ExternalStoreRefused
     */
    public function handle(ExternalStore $store): array
    {
        if (! $store->status->isLive()) {
            throw ExternalStoreRefused::notLive();
        }

        try {
            $this->platforms->driver($store->platform)->verify($store->endpoint());
        } catch (StorePlatformException $failure) {
            return ['ok' => false, 'message' => $failure->getMessage()];
        }

        return ['ok' => true, 'message' => null];
    }
}
