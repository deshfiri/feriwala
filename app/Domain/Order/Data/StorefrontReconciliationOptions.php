<?php

namespace App\Domain\Order\Data;

/**
 * What one legacy reconciliation run is asked to do. There is deliberately no
 * option that bypasses eligibility or idempotency.
 */
final readonly class StorefrontReconciliationOptions
{
    public function __construct(
        public bool $apply = false,
        public ?string $order = null,
        public ?string $partner = null,
        public ?string $provider = null,
        public ?string $resume = null,
        public bool $courierApi = true,
        public ?int $limit = null,
        /** The first pause before a temporary courier failure is retried; it doubles. */
        public int $retryBaseMilliseconds = 1000,
        /** Between courier calls, to stay inside the provider's rate limit. */
        public int $pauseMilliseconds = 250,
        /** One Storefront, by its public id. */
        public ?string $website = null,
        /** Orders placed on or after / on or before these dates (Y-m-d). */
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'apply' => $this->apply,
            'order' => $this->order,
            'partner' => $this->partner,
            'provider' => $this->provider,
            'courier_api' => $this->courierApi,
            'limit' => $this->limit,
            'website' => $this->website,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    /**
     * A resumed run keeps the options it was started with; only the pacing of
     * this sitting comes from `$current`.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored, self $current): self
    {
        return new self(
            apply: (bool) ($stored['apply'] ?? true),
            order: $stored['order'] ?? null,
            partner: $stored['partner'] ?? null,
            provider: $stored['provider'] ?? null,
            resume: $current->resume,
            courierApi: (bool) ($stored['courier_api'] ?? true),
            limit: $current->limit,
            retryBaseMilliseconds: $current->retryBaseMilliseconds,
            pauseMilliseconds: $current->pauseMilliseconds,
            website: $stored['website'] ?? null,
            from: $stored['from'] ?? null,
            to: $stored['to'] ?? null,
        );
    }
}
