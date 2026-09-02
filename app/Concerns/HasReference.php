<?php

namespace App\Concerns;

use App\Support\References\Reference;
use App\Support\References\ReferencePrefix;
use RuntimeException;

/**
 * Assigns a unique human-readable reference on creation.
 *
 * The generated segment is random rather than sequential, so references cannot be
 * used to infer order volume or to guess a neighbour's record. Collisions are
 * astronomically unlikely but not impossible, so generation retries a bounded
 * number of times; the column's unique index remains the actual guarantee.
 */
trait HasReference
{
    protected const REFERENCE_ATTEMPTS = 5;

    public static function bootHasReference(): void
    {
        static::creating(function ($model) {
            $column = $model->referenceColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, $model->generateReference());
            }
        });
    }

    /**
     * The prefix identifying this model's references.
     */
    abstract public function referencePrefix(): ReferencePrefix;

    /**
     * The column holding the reference.
     */
    public function referenceColumn(): string
    {
        return 'reference';
    }

    /**
     * Produce a reference not already taken by another row.
     */
    public function generateReference(): string
    {
        $column = $this->referenceColumn();

        for ($attempt = 0; $attempt < self::REFERENCE_ATTEMPTS; $attempt++) {
            $reference = Reference::generate($this->referencePrefix());

            if (! static::query()->where($column, $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException(sprintf(
            'Could not generate a unique %s reference for %s after %d attempts.',
            $this->referencePrefix()->value,
            static::class,
            self::REFERENCE_ATTEMPTS,
        ));
    }
}
