<?php

namespace App\Http\Resources\Mobile\Websites;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue product this storefront sells, at the partner's own price.
 *
 * Built from {@see \App\Domain\Website\Queries\WebsiteCatalogue::row()}. What
 * Feriwala paid for the product is never here; `terms` are the selling-side
 * bounds an administrator set, so the app can say what a price may be changed
 * to (the server checks them again on every save).
 *
 * @property array<string, mixed> $resource
 */
class WebsiteProductResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            /** Public identifier (ULID) of the selection. */
            'id' => $row['id'],
            /** @var array{id: string, name: string, sku: string, image: string|null} */
            'product' => $row['product'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'sync_status' => $row['sync_status'],
            'sync_label' => $row['sync_label'],
            'last_synced_at' => $row['last_synced_at'],
            'sync_error' => $row['sync_error'],
            /** @var array{amount: string, currency: string, formatted: string}|null */
            'price' => $row['price'],
            /** @var array{amount: string, currency: string, formatted: string}|null */
            'promotional_price' => $row['promotional_price'],
            'promotion_starts_at' => $row['promotion_starts_at'],
            'promotion_ends_at' => $row['promotion_ends_at'],
            /** Whether the promotion is in force right now. */
            'promotion_applies' => $row['promotion_applies'],
            'promo_title' => $row['promo_title'],
            'marketing_description' => $row['marketing_description'],
            'is_featured' => $row['is_featured'],
            'display_order' => $row['display_order'],
            /** @var array{id: string, name: string}|null */
            'category' => $row['category'],
            /** @var array{allows_user_pricing: bool, minimum: array<string, string>|null, maximum: array<string, string>|null, suggested: array<string, string>|null, max_margin_percent: mixed, margin_ceiling: array<string, string>|null, locked_fields: mixed} */
            'terms' => $row['terms'],
            'published_at' => $row['published_at'],
        ];
    }
}
