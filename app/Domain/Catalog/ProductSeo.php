<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/**
 * What a partner website puts in a product page's head and structured data
 * (§11.1, §34.3).
 *
 * Built on the server from the catalogue's own record, so every partner page
 * describes the product the same way, and a storefront renders what it is given
 * rather than assembling its own idea of a product.
 *
 * Two rules matter more than the rest:
 *
 *   - **The price in the schema is the website's selling price**, passed in by
 *     whoever knows it (the storefront contract's resolved price, §5.1 of the
 *     contract). The wholesale price and base cost are never read here, so they
 *     cannot leak into a public page's source.
 *   - **Availability is only stated when it is known.** Until inventory (P3.C)
 *     can say whether a unit is in stock, the offer carries no availability
 *     rather than a guess a search engine would show shoppers.
 *
 * The host is the partner website's, so URLs are passed in rather than built
 * here; a relative path is acceptable for a preview.
 */
class ProductSeo
{
    public const DESCRIPTION_LENGTH = 160;

    public function __construct(
        protected ProductMediaStore $media,
    ) {}

    /**
     * The page's title, description, keywords and sharing image.
     *
     * Every field falls back to something the product already says, so a
     * product nobody wrote SEO copy for still has a title and a description.
     *
     * @return array{title: string, description: string|null, keywords: string|null, image: array{url: string, alt: string|null}|null}
     */
    public function metadata(Product $product): array
    {
        $source = $product->meta_description
            ?? $product->short_description
            ?? $product->description;

        $image = $this->shareImage($product);

        return [
            'title' => $product->meta_title ?? $product->name,
            'description' => $source === null ? null : $this->trimToWords($source, self::DESCRIPTION_LENGTH),
            'keywords' => $product->meta_keywords,
            'image' => $image === null ? null : [
                'url' => $this->media->url($image->path),
                'alt' => $image->alt_text,
            ],
        ];
    }

    /**
     * A schema.org Product for JSON-LD.
     *
     * @return array<string, mixed>
     */
    public function schema(Product $product, string $url, ?Money $sellingPrice = null, ?bool $inStock = null): array
    {
        $product->loadMissing(['brand', 'media']);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'sku' => $product->sku,
            'url' => $url,
        ];

        $metadata = $this->metadata($product);

        if ($metadata['description'] !== null) {
            $schema['description'] = $metadata['description'];
        }

        $images = $product->media
            ->where('type', ProductMedia::TYPE_IMAGE)
            ->map(fn (ProductMedia $image) => $this->media->url($image->path))
            ->values()
            ->all();

        if ($images !== []) {
            $schema['image'] = $images;
        }

        if ($product->barcode !== null) {
            $schema[$this->gtinKey($product->barcode)] = $product->barcode;
        }

        if ($product->mpn !== null) {
            $schema['mpn'] = $product->mpn;
        }

        if ($product->brand !== null) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $product->brand->name];
        }

        $schema['itemCondition'] = $product->item_condition->schemaUrl();

        if ($sellingPrice !== null) {
            $offer = [
                '@type' => 'Offer',
                'url' => $url,
                'price' => $sellingPrice->toDecimal(),
                'priceCurrency' => $sellingPrice->currency->value,
                'itemCondition' => $product->item_condition->schemaUrl(),
            ];

            if ($inStock !== null) {
                $offer['availability'] = $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
            }

            $schema['offers'] = $offer;
        }

        return $schema;
    }

    /**
     * A schema.org BreadcrumbList: category, subcategory, product (§34.3).
     *
     * @param  callable(Category): string  $categoryUrl
     * @return array<string, mixed>
     */
    public function breadcrumbs(Product $product, callable $categoryUrl, string $productUrl): array
    {
        $product->loadMissing('category.parent');

        $trail = array_values(array_filter([$product->category->parent, $product->category]));

        $items = [];

        foreach ($trail as $index => $category) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $category->name,
                'item' => $categoryUrl($category),
            ];
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => count($items) + 1,
            'name' => $product->name,
            'item' => $productUrl,
        ];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * The sharing image: the one chosen, else the first image.
     */
    protected function shareImage(Product $product): ?ProductMedia
    {
        if ($product->social_media_id !== null) {
            $product->loadMissing('socialImage');

            if ($product->socialImage !== null) {
                return $product->socialImage;
            }
        }

        return $product->media()->where('type', ProductMedia::TYPE_IMAGE)->first();
    }

    /**
     * schema.org names a GTIN by its length; anything else is the generic key.
     */
    protected function gtinKey(string $barcode): string
    {
        return match (preg_match('/^\d+$/', $barcode) === 1 ? strlen($barcode) : 0) {
            8 => 'gtin8',
            12 => 'gtin12',
            13 => 'gtin13',
            14 => 'gtin14',
            default => 'gtin',
        };
    }

    /**
     * Shorten to a length without cutting a word in half.
     */
    protected function trimToWords(string $text, int $length): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($plain) <= $length) {
            return $plain;
        }

        return Str::of($plain)->limit($length - 1, preserveWords: true)->rtrim(' .,;:')->append('…')->toString();
    }
}
