<?php

namespace App\Http\Resources\Mobile\Websites;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the account's websites as a row in its list.
 *
 * Built from {@see \App\Domain\Website\Queries\WebsiteOverview::summary()}, the
 * array the web list shows. The registrar and the hosting provider are
 * Feriwala's suppliers and never appear.
 *
 * @property array<string, mixed> $resource
 */
class WebsiteResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array{id: string, name: string, host: string, status: string, status_label: string, is_live: bool, connection_health: string, last_synced_at: string|null, outstanding_charges: int, created_at: string, domain_record: array<string, mixed>|null, hosting_record: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Public identifier (ULID). */
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            /** The address the storefront answers on. */
            'host' => $this->resource['host'],
            'status' => $this->resource['status'],
            'status_label' => $this->resource['status_label'],
            /** Whether the shop is open to customers right now. */
            'is_live' => $this->resource['is_live'],
            'connection_health' => $this->resource['connection_health'],
            'last_synced_at' => $this->resource['last_synced_at'],
            /** How many charges are still due. */
            'outstanding_charges' => $this->resource['outstanding_charges'],
            'created_at' => $this->resource['created_at'],
            /**
             * The domain recorded against the website, once staff have registered it.
             *
             * @var array{domain: string, status: string, status_label: string, registered_at: string|null, expires_at: string|null, days_remaining: int|null}|null
             */
            'domain_record' => $this->resource['domain_record'] ?? null,
            /**
             * The hosting recorded against the website, once it is stood up.
             *
             * @var array{plan: string, status: string, status_label: string, started_at: string|null, expires_at: string|null, days_remaining: int|null}|null
             */
            'hosting_record' => $this->resource['hosting_record'] ?? null,
        ];
    }
}
