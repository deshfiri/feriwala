<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly ApiCache $cache, private readonly Orders $orders) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function receive(string $rawBody, ?string $signature, ?string $timestamp, ?string $deliveryId, ?string $event): array
    {
        if (! $this->verify($rawBody, $signature, $timestamp)) {
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
        $fresh = DB::table('webhook_deliveries')->insertOrIgnore([
            'delivery_id' => $deliveryId,
            'event' => substr($event, 0, 64),
            'occurred_at' => self::time($payload['occurred_at'] ?? null),
            'received_at' => now()->utc()->toDateTimeString(),
        ]);

        if ($fresh === 0) {
            return ['status' => 200, 'body' => ['status' => 'duplicate']];
        }

        $this->apply($event, is_array($payload['data'] ?? null) ? $payload['data'] : []);

        return ['status' => 200, 'body' => ['status' => 'ok']];
    }

    public function verify(string $rawBody, ?string $signature, ?string $timestamp): bool
    {
        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) config('storefront.webhook_tolerance_seconds')) {
            return false;
        }

        $given = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $secrets = array_values(array_filter([config('storefront.webhook_secret'), config('storefront.webhook_secret_previous')]));

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

    public static function prune(int $days = 14): int
    {
        return DB::table('webhook_deliveries')->where('received_at', '<', now()->subDays($days)->utc()->toDateTimeString())->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(string $event, array $data): void
    {
        if (str_starts_with($event, 'product.') || str_starts_with($event, 'inventory.') || $event === 'category.updated') {
            // Lists and facets are cheap to refetch; one product entry is dropped by id and slug.
            $this->cache->forgetTag(Catalog::TAG);

            return;
        }

        if (str_starts_with($event, 'website.')) {
            $this->cache->forgetTag(Site::TAG);
            $this->cache->forgetTag(Catalog::TAG);

            return;
        }

        $order = is_array($data['order'] ?? null) ? $data['order'] : [];

        if (is_string($order['id'] ?? null) && is_string($order['status'] ?? null)) {
            $this->orders->noteStatus($order['id'], $order['status']);
        }

        // Unknown events are tolerated: acknowledged and ignored (§8.2).
    }

    private static function time(mixed $value): ?string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
