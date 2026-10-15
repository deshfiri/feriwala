<?php

declare(strict_types=1);

namespace Storefront\Controllers;

use Storefront\Domain\Catalog;
use Storefront\Domain\Site;
use Storefront\Erp\ErpException;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\View;

final class CatalogController
{
    public static function home(Request $request): Response
    {
        $featured = [];
        $newest = [];

        try {
            $featured = Catalog::products(['featured' => 'true', 'sort' => 'featured'], null, 8)['products'];
            $newest = Catalog::products(['sort' => 'newest'], null, 8)['products'];
        } catch (ErpException) {
            // A home page without products is still a home page.
        }

        $deals = [];

        try {
            $deals = Catalog::products(['flash_deal' => 'true'], null, 4)['products'];
        } catch (ErpException) {
        }

        return View::render('home', [
            'title' => (string) Site::get()['name'],
            'featured' => $featured !== [] ? $featured : $newest,
            'featuredIsNewest' => $featured === [],
            'newest' => $featured !== [] ? $newest : [],
            'deals' => $deals,
        ]);
    }

    /**
     * Every product list: the whole shop, a category, and search results.
     */
    public static function listing(Request $request, ?string $categorySlug): Response
    {
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'sort' => $request->string('sort'),
            'price_min' => $request->string('price_min'),
            'price_max' => $request->string('price_max'),
            'category' => $categorySlug ?? $request->string('category'),
            'brand' => $request->string('brand'),
            'in_stock' => $request->string('in_stock') === '1' ? 'true' : '',
            'attributes' => is_array($request->query['attributes'] ?? null) ? $request->query['attributes'] : [],
        ];

        $cursor = $request->string('cursor');
        $error = null;
        $page = ['products' => [], 'next_cursor' => null, 'has_more' => false];

        try {
            $page = Catalog::products($filters, $cursor !== '' ? $cursor : null, 24);
        } catch (ErpException $exception) {
            // The ERP names a bad filter in words; a customer sees one sentence.
            $error = $exception->status === 422 ? 'catalog.bad_filter' : 'catalog.unavailable';
        }

        $facets = $error === null ? Catalog::facets($filters) : ['brands' => [], 'attributes' => [], 'price_range' => null];
        $category = null;

        foreach (Catalog::categories() as $candidate) {
            if (($candidate['slug'] ?? null) === $filters['category'] && $filters['category'] !== '') {
                $category = $candidate;
            }
        }

        $title = $category['name'] ?? ($filters['q'] !== '' ? t('catalog.search_results', ['q' => $filters['q']]) : t('catalog.all_products'));

        return View::render('catalog/index', [
            'title' => (string) $title,
            'products' => $page['products'],
            'nextCursor' => $page['next_cursor'],
            'hasMore' => $page['has_more'],
            'facets' => $facets,
            'filters' => $filters,
            'category' => $category,
            'categorySlug' => $categorySlug,
            'error' => $error,
            'hasCursor' => $cursor !== '',
        ]);
    }

    public static function show(Request $request, string $slug): Response
    {
        $product = Catalog::product($slug);

        if ($product === null) {
            return View::render('errors/404', [], true, 404);
        }

        return View::render('catalog/show', [
            'title' => (string) ($product['seo']['title'] ?? $product['name'] ?? ''),
            'description' => plain_text((string) ($product['seo']['description'] ?? $product['short_description'] ?? ''), 160),
            'product' => $product,
            'image' => Catalog::primaryImage($product),
        ]);
    }
}
