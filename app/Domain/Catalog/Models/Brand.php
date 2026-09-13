<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One product brand (§11.3).
 *
 * Flat by design. A brand answers who made something; where it sits in the
 * catalogue is the category's job, and a nested brand would be a second
 * arrangement disagreeing with the first.
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $logo_path
 * @property string|null $logo_alt
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 */
class Brand extends Model
{
    /*
     * The same two public identifiers a category has, resolved the same way:
     * administration URLs carry the public id, partner storefronts the slug, and
     * the public id wins the route binding because that is what the panel's
     * routes carry.
     */
    use HasPublicId, HasSlug {
        HasPublicId::getRouteKeyName insteadof HasSlug;
    }

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
