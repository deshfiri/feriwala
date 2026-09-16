<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\WebsiteCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A storefront reading its own arrangement (contract §5.1, P5-4).
 *
 * The shop's own categories, the ones its partner made visible, in the order
 * the partner arranged them. Another website's category is a `404`.
 */
class CategoryController extends StorefrontController
{
    public function index(Request $request): JsonResponse
    {
        $page = WebsiteCategory::query()
            ->where('website_id', $this->website($request)->id)
            ->where('is_active', true)
            ->when($request->filled('updated_since'), fn ($query) => $query
                ->where('updated_at', '>=', $request->date('updated_since')))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request));

        return new JsonResponse($this->envelope(
            $page,
            collect($page->items())->map(fn (WebsiteCategory $category) => $this->category($category))->all(),
        ));
    }

    public function show(Request $request, string $category): JsonResponse
    {
        /** @var WebsiteCategory|null $record */
        $record = WebsiteCategory::query()
            ->where('website_id', $this->website($request)->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('public_id', $category)->orWhere('slug', $category))
            ->first();

        if ($record === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such category on this website.');
        }

        return new JsonResponse($this->category($record));
    }

    /**
     * @return array<string, mixed>
     */
    protected function category(WebsiteCategory $category): array
    {
        return [
            'id' => $category->public_id,
            'slug' => $category->slug,
            'name' => $category->name,
            'position' => $category->position,
            'updated_at' => $category->updated_at->toIso8601String(),
        ];
    }
}
