<?php

namespace App\Integrations\Store\Contracts;

use App\Integrations\Store\Data\StoreEndpoint;
use App\Integrations\Store\Data\StoreOrderPage;
use App\Integrations\Store\Data\StoreWebhook;
use App\Integrations\Store\Exceptions\StorePlatformException;
use Carbon\CarbonImmutable;

/**
 * What Feriwala needs from a store platform, and nothing more (D29).
 *
 * Read-only by design: the store is the source of truth for its own orders, so
 * nothing here writes to a store. A platform is added by writing its driver.
 */
interface StorePlatformDriver
{
    /**
     * Prove the address and credentials can read orders.
     *
     * @throws StorePlatformException
     */
    public function verify(StoreEndpoint $endpoint): void;

    /**
     * One page of orders after `$sinceId`, optionally only those changed since
     * `$updatedSince`.
     *
     * @throws StorePlatformException
     */
    public function fetchOrders(StoreEndpoint $endpoint, int $sinceId, ?CarbonImmutable $updatedSince = null): StoreOrderPage;

    /**
     * Whether a notification really came from the store holding `$secret`.
     *
     * @param  array<string, string|null>  $headers  lower-cased header names
     */
    public function signatureIsValid(string $rawBody, array $headers, string $secret): bool;

    /**
     * The store's own id for this notification, used to recognise a replay.
     *
     * @param  array<string, string|null>  $headers  lower-cased header names
     */
    public function webhookEventId(array $headers): ?string;

    /**
     * Read a verified notification.
     *
     * @param  array<string, mixed>  $payload  decoded without floats
     */
    public function readWebhook(array $payload): StoreWebhook;

    /**
     * The events to subscribe the store to, for the setup instructions.
     *
     * @return list<string>
     */
    public function webhookEvents(): array;
}
