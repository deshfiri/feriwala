<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Creating, editing and retiring brands (§11.3).
 *
 * Simpler than categories — there is no tree to keep honest — so what this class
 * is really for is the logo: the file is written only after the row is known to
 * be valid, and the file it replaces is removed only after the row holding the
 * new path has been committed. Doing it the other way round is how a brand ends
 * up pointing at a file that was deleted when the save failed.
 *
 * The guard against deleting a brand that is still **on products** lands with
 * products themselves (P3-3), for the same reason it does for categories: a
 * check written against a table nobody has built is a check nobody has tested.
 */
class ManageBrands
{
    /**
     * Where logos live under the public disk.
     */
    public const FOLDER = 'brands';

    public function __construct(
        protected RecordAuditLog $audit,
        protected CatalogImageStore $images,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CatalogRefused
     */
    public function create(User $actor, array $attributes, ?UploadedFile $logo = null): Brand
    {
        // Written before the transaction opens, because a file write is not
        // something a rollback can undo — and an orphaned image is a great deal
        // cheaper than a row pointing at nothing.
        $logoPath = $logo === null ? null : $this->images->store($logo, self::FOLDER);

        try {
            return $this->database->transaction(function () use ($actor, $attributes, $logoPath) {
                $brand = Brand::create([
                    ...$this->fields($attributes),
                    'logo_path' => $logoPath,
                    'sort_order' => $attributes['sort_order'] ?? $this->nextPosition(),
                ]);

                $this->record($actor, 'catalog.brand_created', $brand, after: $this->snapshot($brand));

                return $brand;
            });
        } catch (Throwable $failure) {
            // Nothing references it, so it is ours to clean up.
            $this->images->delete($logoPath);

            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CatalogRefused
     */
    public function update(User $actor, Brand $brand, array $attributes, ?UploadedFile $logo = null): Brand
    {
        $before = $this->snapshot($brand);
        $replaced = $brand->logo_path;

        $logoPath = $logo === null ? null : $this->images->store($logo, self::FOLDER);

        try {
            $this->database->transaction(function () use ($brand, $attributes, $logoPath) {
                $brand->fill($this->fields($attributes));

                if ($logoPath !== null) {
                    $brand->logo_path = $logoPath;
                } elseif (($attributes['remove_logo'] ?? false) === true) {
                    $brand->logo_path = null;
                }

                if (array_key_exists('sort_order', $attributes)) {
                    $brand->sort_order = (int) $attributes['sort_order'];
                }

                $brand->save();
            });
        } catch (Throwable $failure) {
            $this->images->delete($logoPath);

            throw $failure;
        }

        // Only once the row naming the new file is committed. Removed first, a
        // failed save would leave the brand pointing at a deleted image.
        if ($logoPath !== null || ($attributes['remove_logo'] ?? false) === true) {
            $this->images->delete($replaced);
        }

        $this->record($actor, 'catalog.brand_updated', $brand, $before, $this->snapshot($brand));

        return $brand->refresh();
    }

    /**
     * Switch a brand on or off (§11.3).
     *
     * Not a delete. A disabled brand keeps its products and its history and
     * simply stops being offered as a filter or a choice.
     */
    public function setActive(User $actor, Brand $brand, bool $isActive): Brand
    {
        $before = $this->snapshot($brand);

        $brand->forceFill(['is_active' => $isActive])->save();

        $this->record(
            $actor,
            $isActive ? 'catalog.brand_enabled' : 'catalog.brand_disabled',
            $brand,
            $before,
            $this->snapshot($brand),
        );

        return $brand;
    }

    /**
     * Remove a brand outright.
     *
     * Its logo goes with it — nothing else can reference that file, and leaving
     * it would accumulate images nobody can find, let alone remove.
     *
     * @throws CatalogRefused
     */
    public function delete(User $actor, Brand $brand): void
    {
        $logoPath = $brand->logo_path;

        $this->database->transaction(function () use ($actor, $brand) {
            $this->record($actor, 'catalog.brand_deleted', $brand, before: $this->snapshot($brand));

            $brand->delete();
        });

        $this->images->delete($logoPath);
    }

    protected function nextPosition(): int
    {
        return (int) Brand::query()->max('sort_order') + 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes): array
    {
        $fields = [];

        foreach (['name', 'description', 'logo_alt'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[$field] = $attributes[$field];
            }
        }

        /*
         * The slug is deliberately not in that list. `HasSlug` makes one on
         * creation and then leaves it alone, because renaming a brand is not a
         * reason to 404 every storefront link pointing at it — moving one is an
         * explicit act with a redirect attached.
         */
        if (array_key_exists('slug', $attributes) && filled($attributes['slug'])) {
            $fields['slug'] = $attributes['slug'];
        }

        if (array_key_exists('is_active', $attributes)) {
            $fields['is_active'] = (bool) $attributes['is_active'];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Brand $brand): array
    {
        return [
            'slug' => $brand->slug,
            'name' => $brand->name,
            'logo_path' => $brand->logo_path,
            'is_active' => $brand->is_active,
            'sort_order' => $brand->sort_order,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        Brand $brand,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Brand::class,
            auditableId: $brand->id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}
