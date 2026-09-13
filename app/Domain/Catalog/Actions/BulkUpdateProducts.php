<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One change applied to many products from the catalogue list (§11.2).
 *
 * Every product goes through the same action a single change on its editor
 * would — {@see TransitionProduct}, {@see SetSalesChannel}, {@see SetFeatured}
 * — so each is permission-checked for its own move, locked, written to its own
 * history, and refused for its own reasons. A bulk action is never a way round
 * a rule one product would meet.
 *
 * **Each product is its own decision, not one transaction.** Activating forty
 * products where one has no wholesale price activates thirty-nine and names the
 * fortieth with the reason; holding the thirty-nine back would make one missing
 * price everybody's problem. What is reported is exact: how many changed, how
 * many were already that way, and every refusal by product.
 *
 * Nothing destructive is offered in bulk. Deleting is one draft at a time from
 * its editor; retiring many products is archiving them, which keeps the record.
 */
class BulkUpdateProducts
{
    /**
     * The most products one request may name. Well above a page of the list,
     * and low enough that one request cannot hold the application for minutes.
     */
    public const MAX_PRODUCTS = 100;

    public function __construct(
        protected TransitionProduct $transition,
        protected SetSalesChannel $channels,
        protected SetFeatured $featured,
    ) {}

    /**
     * @param  array<int, string>  $publicIds
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    public function transition(User $actor, array $publicIds, ProductStatus $to, ?string $reason = null): array
    {
        return $this->each(
            $publicIds,
            fn (Product $product) => $product->status === $to,
            fn (Product $product) => $this->transition->handle($actor, $product, $to, $reason),
            fn (Product $product) => [
                'from' => __('catalog.products.status.'.$product->status->value),
                'to' => __('catalog.products.status.'.$to->value),
            ],
        );
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    public function channel(User $actor, array $publicIds, SalesChannel $channel, bool $enable): array
    {
        return $this->each(
            $publicIds,
            fn (Product $product) => $product->sellsThrough($channel) === $enable,
            fn (Product $product) => $this->channels->handle($actor, $product, $channel, $enable),
        );
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    public function feature(User $actor, array $publicIds, bool $featured): array
    {
        return $this->each(
            $publicIds,
            fn (Product $product) => $product->is_featured === $featured,
            fn (Product $product) => $this->featured->handle($actor, $product, $featured),
        );
    }

    /**
     * Apply `$change` to each named product that is not already `$unchanged`.
     *
     * A product changed by somebody else between this read and the action's own
     * locked read is caught by the action and reported as a refusal with its
     * reason, never silently overwritten.
     *
     * @param  array<int, string>  $publicIds
     * @param  Closure(Product): bool  $unchanged
     * @param  Closure(Product): mixed  $change
     * @param  (Closure(Product): array<string, string>)|null  $moveLabels
     * @return array{changed: int, unchanged: int, refused: array<int, array{id: string, name: string|null, sku: string|null, reason: string}>}
     */
    protected function each(array $publicIds, Closure $unchanged, Closure $change, ?Closure $moveLabels = null): array
    {
        $ids = array_values(array_unique($publicIds));

        $products = Product::query()
            ->whereIn('public_id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $result = ['changed' => 0, 'unchanged' => 0, 'refused' => []];

        foreach ($products as $product) {
            if ($unchanged($product)) {
                $result['unchanged']++;

                continue;
            }

            try {
                $change($product);
                $result['changed']++;
            } catch (AuthorizationException $refused) {
                $result['refused'][] = $this->refusal($product, $moveLabels === null
                    ? __('catalog.products.bulk.not_permitted')
                    : __('catalog.products.bulk.not_permitted_move', $moveLabels($product)));
            } catch (IllegalStateTransition) {
                $result['refused'][] = $this->refusal($product, __('catalog.products.bulk.illegal_move', $moveLabels === null ? [] : $moveLabels($product)));
            } catch (CatalogRefused $refused) {
                $result['refused'][] = $this->refusal($product, $refused->getMessage());
            }
        }

        // A product deleted since the list was loaded is named, not ignored.
        foreach (array_diff($ids, $products->pluck('public_id')->all()) as $missing) {
            $result['refused'][] = ['id' => $missing, 'name' => null, 'sku' => null, 'reason' => __('catalog.products.bulk.missing')];
        }

        return $result;
    }

    /**
     * @return array{id: string, name: string, sku: string, reason: string}
     */
    protected function refusal(Product $product, string $reason): array
    {
        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'reason' => $reason,
        ];
    }
}
