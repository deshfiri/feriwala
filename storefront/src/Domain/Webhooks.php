<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\Support\ApiCache;
use Storefront\Support\Db;
use Storefront\Support\Env;

/**
 * Receiving the ERP's webhooks (contract §7).
 *
 * A webhook is a hint that something changed, not a source of truth (§7.5): all
 * it does here is drop what is cached about it, so the next read goes to the ERP.
 * That is why a lost, late or duplicated delivery costs freshness and never
 * correctness.
 *
 * Verified in constant time against the current secret and, during a rotation,
 * the previous one; rejected when older than five minutes; deduplicated on the
 * delivery id.
 */
final class Webhooks
{
    public const TOLERANCE_SECONDS = 300;

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function receive(string $rawBody, ?string $signature, ?string $timestamp, ?string $deliveryId, ?string $event): array
    {
        if (! self::verify($rawBody, $signature, $timestamp)) {
            return ['status' => 401, 'body' => ['error' => 'invalid_signature']];
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return ['status' => 400, 'body' => ['error' => 'malformed']];
        }

        $deliveryId = $deliveryId !== null && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $deliveryId) === 1 ? $deliveryId : null;

        if ($deliveryId === null) {
            return ['status' => 400, 'body' => ['error' => 'missing_delivery_id']];
        }

        $event = is_string($payload['event'] ?? null) ? $payload['event'] : (string) $event;

        // A repeat is acknowledged and not processed again.
        $fresh = Db::exec(
            'INSERT IGNORE INTO webhook_deliveries (delivery_id, event, occurred_at, received_at) VALUES (?, ?, ?, ?)',
            [$deliveryId, substr($event, 0, 64), self::time($payload['occurred_at'] ?? null), Db::now()],
        );

        if ($fresh === 0) {
            return ['status' => 200, 'body' => ['status' => 'duplicate']];
        }

        self::apply($event, is_array($payload['data'] ?? null) ? $payload['data'] : []);

        return ['status' => 200, 'body' => ['status' => 'ok']];
    }

    public static function verify(string $rawBody, ?string $signature, ?string $timestamp): bool
    {
        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $given = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $secrets = array_values(array_filter([Env::get('WEBHOOK_SECRET'), Env::get('WEBHOOK_SECRET_PREVIOUS')]));

        if ($secrets === []) {
            return false;
        }

        $matched = false;

        // Every secret is tried whatever the first answered, so timing says
        // nothing about which one was nearly right.
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), strtolower($given))) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function apply(string $event, array $data): void
    {
        if (str_starts_with($event, 'product.') || str_starts_with($event, 'inventory.') || $event === 'category.updated') {
            // Lists and facets are cheap to refetch; one product entry is dropped by id and slug.
            ApiCache::forgetTag(Catalog::TAG);

            return;
        }

        if (str_starts_with($event, 'website.')) {
            ApiCache::forgetTag(Site::TAG);
            ApiCache::forgetTag(Catalog::TAG);

            return;
        }

        $order = is_array($data['order'] ?? null) ? $data['order'] : [];

        if (is_string($order['id'] ?? null) && is_string($order['status'] ?? null)) {
            Orders::noteStatus($order['id'], $order['status']);
        }

        // Unknown events are tolerated: acknowledged and ignored (§8.2).
    }

    public static function prune(int $days = 14): int
    {
        return Db::exec('DELETE FROM webhook_deliveries WHERE received_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    private static function time(mixed $value): ?string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
