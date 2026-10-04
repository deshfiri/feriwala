<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductBarcode;
use Illuminate\Support\Str;

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
     * A BPC built from the product's name, with a random tail so two
     * products named alike never collide.
     *
     * @throws CatalogRefused
     */
    public function sku(string $name): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper(Str::ascii($name))), '-');
        $base = $base !== '' ? mb_substr($base, 0, 40) : 'PRODUCT';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $base.'-'.mb_strtoupper(Str::random(6));

            if (! $this->skuTaken($candidate)) {
                return $candidate;
            }
        }

        throw CatalogRefused::noFreeSku($base);
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
