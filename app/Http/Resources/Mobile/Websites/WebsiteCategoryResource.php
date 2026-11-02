<?php

namespace App\Http\Resources\Mobile\Websites;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the storefront's own categories (the partner's arrangement of the shop).
 *
 * @property array<string, mixed> $resource
 */
class WebsiteCategoryResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array{id: string, name: string, slug: string, position: int, is_active: bool, products_count: int}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Public identifier (ULID). */
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'slug' => $this->resource['slug'],
            'position' => $this->resource['position'],
            'is_active' => $this->resource['is_active'],
            'products_count' => $this->resource['products_count'],
        ];
    }
}
