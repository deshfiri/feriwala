<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * A partner arranging their own shop (§15, P5-4).
 *
 * These are the storefront's categories, not the catalogue's. Feriwala's
 * categories are the platform's (§11.3) and a partner cannot write them; this
 * is where a shop says "Eid collection" without touching anything central.
 *
 * Removing one does not remove what is in it: the selections it held simply
 * lose their placement. A category is an arrangement, and deleting an
 * arrangement should never delete the goods.
 */
class ManageWebsiteCategories
{
    public function __construct(
        protected DatabaseManager $database,
        protected SyncWebsiteCatalogue $catalogue,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function create(Website $website, string $name): WebsiteCategory
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        $position = (int) WebsiteCategory::query()
            ->where('website_id', $website->id)
            ->max('position');

        try {
            // Its own savepoint, so a clash rolls back only itself.
            $category = $this->database->transaction(fn () => WebsiteCategory::create([
                'website_id' => $website->id,
                'name' => $name,
                'slug' => $this->slugFor($website, $name),
                'position' => $position + 1,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw WebsiteRefused::categoryNameTaken();
        }

        $this->catalogue->categories($website);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws WebsiteRefused
     */
    public function update(WebsiteCategory $category, array $attributes): WebsiteCategory
    {
        if (array_key_exists('name', $attributes)) {
            $category->name = (string) $attributes['name'];
        }

        if (array_key_exists('is_active', $attributes)) {
            $category->is_active = (bool) $attributes['is_active'];
        }

        $category->save();

        $category->loadMissing('website');
        $this->catalogue->categories($category->website);

        return $category;
    }

    /**
     * Put the categories in the order the partner dragged them into.
     *
     * The whole order in one write, because a screen that moves one row sends
     * a new arrangement — applying it row by row leaves the shop briefly in an
     * order nobody asked for.
     *
     * @param  array<int, string>  $publicIds  in the order they should appear
     */
    public function reorder(Website $website, array $publicIds): void
    {
        $this->database->transaction(function () use ($website, $publicIds) {
            foreach (array_values($publicIds) as $position => $publicId) {
                WebsiteCategory::query()
                    ->where('website_id', $website->id)
                    ->where('public_id', $publicId)
                    ->update(['position' => $position + 1]);
            }
        });

        $this->catalogue->categories($website);
    }

    /**
     * Remove the arrangement, never what it held.
     */
    public function delete(WebsiteCategory $category): void
    {
        $category->loadMissing('website');
        $website = $category->website;

        $this->database->transaction(function () use ($category) {
            WebsiteProduct::query()
                ->where('website_category_id', $category->id)
                ->update(['website_category_id' => null]);

            $category->delete();
        });

        $this->catalogue->categories($website);
    }

    protected function slugFor(Website $website, string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        while (WebsiteCategory::query()
            ->where('website_id', $website->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
