<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product category (§11.3).
 *
 * Nested through `parent_id`. §11.1 asks a product for a "Category" and a
 * "Subcategory"; a product here points at **one** category — the most specific
 * one — and the parent is read from the tree. Storing both on the product would
 * be two answers to one question, and they disagree the first time a
 * subcategory is moved.
 *
 * Depth is capped at {@see MAX_DEPTH}. §11.3 describes categories and
 * subcategories, not an arbitrary hierarchy, and a tree nobody bounded is one
 * that eventually cannot be rendered in a menu or reasoned about in a filter.
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property int|null $parent_id
 * @property string|null $description
 * @property string|null $image_path
 * @property string|null $image_alt
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read int|null $products_count
 */
class Category extends Model
{
    /*
     * Both traits offer a route key, and a category has two public identifiers
     * for two different audiences: administration URLs address it by public id,
     * partner storefronts by slug. The public id wins the binding because that
     * is what the panel's routes carry — a storefront resolving by slug does so
     * explicitly, where the choice is visible.
     */
    use HasPublicId, HasSlug {
        HasPublicId::getRouteKeyName insteadof HasSlug;
    }

    /**
     * A category and its subcategories, and no deeper.
     *
     * Root is depth 1, so a subcategory is depth 2.
     */
    public const MAX_DEPTH = 2;

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
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Products filed directly under this category.
     *
     * Not its subcategories' products — {@see descendantIds()} is for that.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Whether this category may hold products directly.
     *
     * Any category may. A storefront that only allowed leaves to carry products
     * would force an administrator to invent a subcategory for a range that has
     * none, which is a worse catalogue than one with a few shallow branches.
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * How deep this category sits, counting itself.
     */
    public function depth(): int
    {
        $depth = 1;
        $parent = $this->parent;

        while ($parent !== null && $depth < self::MAX_DEPTH + 1) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    /**
     * Whether a category is available for use.
     *
     * A subcategory under a disabled parent is unavailable however its own flag
     * reads — otherwise switching a category off would leave its children on
     * partner storefronts, which is the opposite of what the administrator
     * asked for (§11.3).
     */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->parent === null || $this->parent->isAvailable();
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Every category beneath this one, itself included.
     *
     * Used by catalogue filters: choosing a parent should show what is in its
     * subcategories too, because that is what a shopper means by picking it.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = [...$ids, ...$child->descendantIds()];
        }

        return $ids;
    }
}
