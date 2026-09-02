<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gives a model a unique, human-readable slug derived from a source attribute.
 *
 * Public URLs on the landing site and on partner storefronts must be readable and
 * must not contain database IDs or arbitrary numbers (requirements.txt §34.3).
 *
 * A slug is generated once, on creation, and then left alone. Regenerating it when
 * the source attribute changes would silently break every link, share, and search
 * result pointing at the old URL — renaming a product is not a reason to 404 its
 * page. Call {@see regenerateSlug()} explicitly when a rename really should move
 * the URL, and pair it with a 301 redirect.
 *
 * @method static Builder<static> query()
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::creating(function ($model) {
            $column = $model->slugColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, $model->generateSlug());
            }
        });
    }

    /**
     * The attribute the slug is derived from.
     */
    public function slugSource(): string
    {
        return 'name';
    }

    /**
     * The column holding the slug.
     */
    public function slugColumn(): string
    {
        return 'slug';
    }

    /**
     * Route model binding resolves on the slug.
     */
    public function getRouteKeyName(): string
    {
        return $this->slugColumn();
    }

    /**
     * Build a slug that no other row is using.
     *
     * Collisions get a numeric suffix based on the highest existing suffix, so
     * "navy-panjabi", "navy-panjabi-2", "navy-panjabi-3" stay in sequence instead
     * of colliding again at "-2".
     */
    public function generateSlug(?string $from = null): string
    {
        $base = Str::slug($from ?? (string) $this->getAttribute($this->slugSource()));

        if ($base === '') {
            $base = Str::lower(class_basename(static::class));
        }

        $taken = $this->existingSlugs($base);

        if (! $taken->contains($base)) {
            return $base;
        }

        $highest = $taken
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 1;
                }

                return preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $matches)
                    ? (int) $matches[1]
                    : null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 1;

        return $base.'-'.($highest + 1);
    }

    /**
     * Deliberately move this model to a new slug.
     *
     * Does not persist, and does not create the redirect — the caller owns both,
     * so the rename and its 301 land in one transaction (§34.1, §34.3).
     */
    public function regenerateSlug(?string $from = null): static
    {
        $this->setAttribute($this->slugColumn(), $this->generateSlug($from));

        return $this;
    }

    /**
     * Slugs already in use that could collide with the given base.
     *
     * @return Collection<int, string>
     */
    protected function existingSlugs(string $base): Collection
    {
        $column = $this->slugColumn();

        $query = static::query()
            ->where(function (Builder $query) use ($column, $base) {
                $query->where($column, $base)
                    ->orWhere($column, 'like', $base.'-%');
            });

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        // Soft-deleted rows still own their slugs — reusing one would resurrect a
        // dead URL pointing at different content.
        if (method_exists($this, 'withTrashed')) {
            $query->withTrashed();
        }

        return $query->pluck($column);
    }
}
