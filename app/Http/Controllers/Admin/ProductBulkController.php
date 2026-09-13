<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\BulkUpdateProducts;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkProductRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Bulk actions on the catalogue list (§11.2).
 *
 * The request refuses anybody who holds none of the permissions a bulk action
 * could use, before a rule runs. This controller then asks for the permission
 * the chosen action needs — a lifecycle target nobody with this person's
 * permissions could ever reach is a 403, not a list of forty identical
 * refusals. Each product is still checked for its own move by the action.
 */
class ProductBulkController extends Controller
{
    public function __construct(
        protected BulkUpdateProducts $bulk,
    ) {}

    public function __invoke(BulkProductRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array<int, string> $products */
        $products = $request->validated('products');

        $result = match ($request->validated('action')) {
            BulkProductRequest::TRANSITION => $this->transition($actor, $products, $request),
            BulkProductRequest::CHANNEL => $this->channel($actor, $products, $request),
            default => $this->feature($actor, $products, $request),
        };

        Inertia::flash([
            'toast' => [
                'type' => $result['refused'] === [] ? 'success' : 'warning',
                'message' => __('catalog.products.bulk.summary', [
                    'changed' => $result['changed'],
                    'unchanged' => $result['unchanged'],
                    'refused' => count($result['refused']),
                ]),
            ],
            'bulk_result' => $result,
        ]);

        return back();
    }

    /**
     * @param  array<int, string>  $products
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    protected function transition(User $actor, array $products, BulkProductRequest $request): array
    {
        $to = ProductStatus::from((string) $request->validated('status'));

        abort_unless(CatalogPolicy::canMoveAnyProductTo($actor, $to), 403);

        $reason = $request->validated('reason');

        return $this->bulk->transition($actor, $products, $to, is_string($reason) ? $reason : null);
    }

    /**
     * @param  array<int, string>  $products
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    protected function channel(User $actor, array $products, BulkProductRequest $request): array
    {
        $enable = $request->boolean('enable');

        abort_unless(CatalogPolicy::canSetChannel($actor, $enable), 403);

        return $this->bulk->channel($actor, $products, SalesChannel::from((string) $request->validated('channel')), $enable);
    }

    /**
     * @param  array<int, string>  $products
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    protected function feature(User $actor, array $products, BulkProductRequest $request): array
    {
        abort_unless(CatalogPolicy::canPublish($actor), 403);

        return $this->bulk->feature($actor, $products, $request->boolean('enable'));
    }
}
