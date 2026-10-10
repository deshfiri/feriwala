<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductBarcode;

/**
 * A BPC (SKU) or barcode an administrator left blank, invented rather than
 * demanded (§11.1).
 *
 * Both share one namespace with variant codes — the database trigger holds it
 * too — so every candidate is checked against both tables before it is
 * offered, the same retry-on-collision shape {@see GenerateVariants} uses for
 * a variant's own SKU. A barcode is always a 13-digit EAN-13 code, valid
 * check digit included, so the one it generates can be downloaded and
 * actually scanned.
 */
class GenerateProductIdentifiers
{
    public const MAX_ATTEMPTS = 50;

    /**
     * A BPC is exactly nine upper-case letters and digits, with at least one
     * of each — a code, not a slug, so it carries nothing of the product's name.
     */
    public const BPC_LENGTH = 9;

    public const BPC_PATTERN = '/^(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{9}$/';

    private const BPC_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * A free nine-character alphanumeric BPC.
     *
     * @throws CatalogRefused
     */
    public function sku(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = '';

            for ($position = 0; $position < self::BPC_LENGTH; $position++) {
                $candidate .= self::BPC_ALPHABET[random_int(0, strlen(self::BPC_ALPHABET) - 1)];
            }

            if (preg_match(self::BPC_PATTERN, $candidate) === 1 && ! $this->skuTaken($candidate)) {
                return $candidate;
            }
        }

        throw CatalogRefused::noFreeSku();
    }

    /**
     * A 13-digit EAN-13 barcode nothing else in the catalogue already holds.
     *
     * @throws CatalogRefused
     */
    public function barcode(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $base = str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
            $candidate = $base.ProductBarcode::checkDigit($base);

            if (! $this->barcodeTaken($candidate)) {
                return $candidate;
            }
        }

        throw CatalogRefused::noFreeBarcode();
    }

    protected function skuTaken(string $sku): bool
    {
        return Product::query()->where('sku', $sku)->exists()
            || ProductVariant::query()->where('sku', $sku)->exists();
    }

    protected function barcodeTaken(string $barcode): bool
    {
        return Product::query()->where('barcode', $barcode)->exists()
            || ProductVariant::query()->where('barcode', $barcode)->exists();
    }
}
