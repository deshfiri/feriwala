<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Creating, editing and retiring categories (§11.3).
 *
 * The rules live here rather than in the controller, so reaching a category
 * from a command, a job or a second controller cannot skip them:
 *
 *   - depth is capped, because §11.3 describes categories and subcategories
 *     rather than an arbitrary hierarchy;
 *   - a category cannot be moved inside its own subtree, because a cycle makes
 *     every walk of the tree run until it exhausts memory;
 *   - a category holding subcategories is not deleted. §11.3 gives
 *     administrators enable/disable for exactly that case, and switching one off
 *     leaves the history of what was sold under it intact.
 *
 * The guard against deleting a category that still holds **products** lands with
 * products themselves (P3-3): the table it would count does not exist yet, and a
 * check written against a table nobody has built is a check nobody has tested.
 */
class ManageCategories
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CatalogRefused
     */
    public function create(User $actor, array $attributes): Category
    {
        return $this->database->transaction(function () use ($actor, $attributes) {
            $parent = $this->parentFrom($attributes);

            $this->assertDepth($parent);

            $category = Category::create([
                ...$this->fields($attributes),
                'parent_id' => $parent?->id,

                // Appended to its siblings. A new category arriving at the top
                // would reorder a menu somebody arranged deliberately.
                'sort_order' => $attributes['sort_order'] ?? $this->nextPosition($parent?->id),
            ]);

            $this->record($actor, 'catalog.category_created', $category, after: $this->snapshot($category));

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CatalogRefused
     */
    public function update(User $actor, Category $category, array $attributes): Category
    {
        return $this->database->transaction(function () use ($actor, $category, $attributes) {
            $before = $this->snapshot($category);

            if (array_key_exists('parent_id', $attributes)) {
                $parent = $this->parentFrom($attributes);

                $this->assertNotCyclic($category, $parent);
                $this->assertDepth($parent, $category);

                $category->parent_id = $parent?->id;
            }

            $category->fill($this->fields($attributes));

            if (array_key_exists('sort_order', $attributes)) {
                $category->sort_order = (int) $attributes['sort_order'];
            }

            $category->save();

            $this->record($actor, 'catalog.category_updated', $category, $before, $this->snapshot($category));

            return $category->refresh();
        });
    }

    /**
     * Switch a category on or off (§11.3).
     *
     * Not a delete. A disabled category keeps its products and its history and
     * simply stops being offered — and its subcategories go with it, because
     * leaving them visible under a category somebody switched off is the
     * opposite of what they asked for.
     */
    public function setActive(User $actor, Category $category, bool $isActive): Category
    {
        $before = $this->snapshot($category);

        $category->forceFill(['is_active' => $isActive])->save();

        $this->record(
            $actor,
            $isActive ? 'catalog.category_enabled' : 'catalog.category_disabled',
            $category,
            $before,
            $this->snapshot($category),
        );

        return $category;
    }

    /**
     * Remove a category outright.
     *
     * Only ever an empty one. Anything else is switched off instead, which is
     * what §11.3 asks for and what keeps the record of what was sold under it.
     *
     * @throws CatalogRefused
     */
    public function delete(User $actor, Category $category): void
    {
        $this->database->transaction(function () use ($actor, $category) {
            $children = $category->children()->count();

            if ($children > 0) {
                throw CatalogRefused::categoryHasChildren($children);
            }

            $this->record($actor, 'catalog.category_deleted', $category, before: $this->snapshot($category));

            $category->delete();
        });
    }

    /**
     * Put a branch in a new order (§11.3).
     *
     * Takes the whole sibling list rather than one move, because a reorder is
     * one decision: applying it as a series of single moves leaves the branch
     * in intermediate orders that somebody could read between writes.
     *
     * @param  array<int, string>  $publicIds  siblings, in the order wanted
     */
    public function reorder(User $actor, ?Category $parent, array $publicIds): void
    {
        $this->database->transaction(function () use ($actor, $parent, $publicIds) {
            $siblings = Category::query()
                ->where('parent_id', $parent?->id)
                ->whereIn('public_id', $publicIds)
                ->get()
                ->keyBy('public_id');

            $position = 0;

            foreach ($publicIds as $publicId) {
                $sibling = $siblings->get($publicId);

                if ($sibling === null) {
                    // Silently skipped: an id for a category that has moved or
                    // gone is a stale screen, not something to fail the whole
                    // reorder over.
                    continue;
                }

                $sibling->forceFill(['sort_order' => $position++])->save();
            }

            $this->audit->handle(new AuditEntry(
                action: 'catalog.categories_reordered',
                actorId: $actor->id,
                after: ['parent' => $parent?->public_id, 'order' => $publicIds],
                module: 'catalog',
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CatalogRefused
     */
    protected function parentFrom(array $attributes): ?Category
    {
        $publicId = $attributes['parent_id'] ?? null;

        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Category::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @throws CatalogRefused
     */
    protected function assertDepth(?Category $parent, ?Category $moving = null): void
    {
        $depth = $parent === null ? 1 : $parent->depth() + 1;

        if ($depth > Category::MAX_DEPTH) {
            throw CatalogRefused::tooDeep(Category::MAX_DEPTH);
        }

        /*
         * A category with children cannot move to the deepest level: its
         * children would end up one below that, which is the same violation
         * one step removed.
         */
        if ($moving !== null && $depth === Category::MAX_DEPTH && $moving->children()->exists()) {
            throw CatalogRefused::tooDeep(Category::MAX_DEPTH);
        }
    }

    /**
     * @throws CatalogRefused
     */
    protected function assertNotCyclic(Category $category, ?Category $parent): void
    {
        while ($parent !== null) {
            if ($parent->id === $category->id) {
                throw CatalogRefused::wouldCycle();
            }

            $parent = $parent->parent;
        }
    }

    protected function nextPosition(?int $parentId): int
    {
        return (int) Category::query()->where('parent_id', $parentId)->max('sort_order') + 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes): array
    {
        $fields = [];

        foreach ([
            'name', 'description', 'image_path', 'image_alt',
            'meta_title', 'meta_description', 'meta_keywords',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('is_active', $attributes)) {
            $fields['is_active'] = (bool) $attributes['is_active'];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Category $category): array
    {
        return [
            'slug' => $category->slug,
            'name' => $category->name,
            'parent' => $category->parent?->slug,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        Category $category,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Category::class,
            auditableId: $category->id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}
