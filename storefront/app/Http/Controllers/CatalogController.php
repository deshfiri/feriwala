<?php

namespace App\Http\Controllers;

use App\Erp\ErpException;
use App\Services\Catalog;
use App\Services\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __construct(private readonly Catalog $catalog, private readonly Site $site) {}

    public function home(): View
    {
        $featured = [];
        $newest = [];

        try {
            $featured = $this->catalog->products(['featured' => 'true', 'sort' => 'featured'], null, 8)['products'];
            $newest = $this->catalog->products(['sort' => 'newest'], null, 8)['products'];
        } catch (ErpException) {
            // A home page without products is still a home page.
        }

        $deals = [];

        try {
            $deals = $this->catalog->products(['flash_deal' => 'true'], null, 4)['products'];
        } catch (ErpException) {
            // Likewise without deals.
        }

        return view('home', [
            'title' => (string) $this->site->get()['name'],
            'featured' => $featured !== [] ? $featured : $newest,
            'featuredIsNewest' => $featured === [],
            'newest' => $featured !== [] ? $newest : [],
            'deals' => $deals,
        ]);
    }

    /**
     * Every product list: the whole shop, a category, and search results.
     */
    public function listing(Request $request, ?string $slug = null): View
    {
        $filters = [
            'q' => mb_substr(request_string($request, 'q'), 0, 100),
            'sort' => request_string($request, 'sort'),
            'price_min' => request_string($request, 'price_min'),
            'price_max' => request_string($request, 'price_max'),
            'category' => $slug ?? request_string($request, 'category'),
            'brand' => request_string($request, 'brand'),
            'in_stock' => request_string($request, 'in_stock') === '1' ? 'true' : '',
            'attributes' => is_array($request->query('attributes')) ? $request->query('attributes') : [],
        ];

        $cursor = request_string($request, 'cursor');
        $error = null;
        $page = ['products' => [], 'next_cursor' => null, 'has_more' => false];

        try {
            $page = $this->catalog->products($filters, $cursor !== '' ? $cursor : null, 24);
        } catch (ErpException $exception) {
            // The ERP names a bad filter in words; a customer sees one sentence.
            $error = $exception->status === 422 ? 'catalog.bad_filter' : 'catalog.unavailable';
        }

        $facets = $error === null ? $this->catalog->facets($filters) : ['brands' => [], 'attributes' => [], 'price_range' => null];
        $category = null;

        foreach ($this->catalog->categories() as $candidate) {
            if (($candidate['slug'] ?? null) === $filters['category'] && $filters['category'] !== '') {
                $category = $candidate;
            }
        }

        $title = $category['name'] ?? ($filters['q'] !== '' ? t('catalog.search_results', ['q' => $filters['q']]) : t('catalog.all_products'));

        return view('catalog.index', [
            'title' => (string) $title,
            'products' => $page['products'],
            'nextCursor' => $page['next_cursor'],
            'hasMore' => $page['has_more'],
            'facets' => $facets,
            'filters' => $filters,
            'category' => $category,
            'categorySlug' => $slug,
            'error' => $error,
            'hasCursor' => $cursor !== '',
        ]);
    }

    public function show(string $slug): View
    {
        $product = $this->catalog->product($slug);

        abort_if($product === null, 404);

        return view('catalog.show', [
            'title' => (string) ($product['seo']['title'] ?? $product['name'] ?? ''),
            'description' => plain_text((string) ($product['seo']['description'] ?? $product['short_description'] ?? ''), 160),
            'product' => $product,
            'image' => Catalog::primaryImage($product),
        ]);
    }
}
