<?php

namespace App\Concerns;

use App\Support\References\PartnerNumber;
use RuntimeException;

/**
 * Gives a partner account its identity number the moment it is created.
 *
 * Soft-deleted rows are checked too: a number that belonged to a closed account
 * is never handed to another one. A collision is vanishingly unlikely but not
 * impossible, so generation retries a bounded number of times; the column's
 * unique index remains the actual guarantee.
 */
trait HasPartnerNumber
{
    protected const PARTNER_NUMBER_ATTEMPTS = 10;

    public static function bootHasPartnerNumber(): void
    {
        static::creating(function ($model): void {
            if (blank($model->getAttribute('partner_number'))) {
                $model->setAttribute('partner_number', $model->generatePartnerNumber());
            }
        });
    }

    /**
     * Produce a number not already taken by any partner, trashed or not.
     */
    public function generatePartnerNumber(): string
    {
        for ($attempt = 0; $attempt < self::PARTNER_NUMBER_ATTEMPTS; $attempt++) {
            $number = PartnerNumber::generate();

            if (! static::query()->withoutGlobalScopes()->where('partner_number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException(sprintf(
            'Could not generate a unique partner number for %s after %d attempts.',
            static::class,
            self::PARTNER_NUMBER_ATTEMPTS,
        ));
    }
}
