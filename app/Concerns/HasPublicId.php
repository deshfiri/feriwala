<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Gives a model a ULID `public_id` and routes by it instead of the primary key.
 *
 * Internal primary keys stay as fast auto-incrementing bigints; the ULID is what
 * appears in URLs and API payloads, so no route ever leaks a database ID or lets
 * a visitor enumerate records by counting (requirements.txt §34.2, §34.3).
 *
 * @method static Builder<static> query()
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $column = $model->publicIdColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::ulid());
            }
        });
    }

    /**
     * The column holding the public identifier.
     */
    public function publicIdColumn(): string
    {
        return 'public_id';
    }

    /**
     * Route model binding resolves on the public id, never the primary key.
     */
    public function getRouteKeyName(): string
    {
        return $this->publicIdColumn();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where($this->publicIdColumn(), $publicId);
    }
}
