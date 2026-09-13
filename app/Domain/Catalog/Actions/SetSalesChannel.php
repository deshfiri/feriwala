<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Switch a product on or off for one sales channel (§11.1, §11.2).
 *
 * The same rules as the lifecycle, applied per channel: switching a channel on
 * puts the product in front of every eligible partner on it, so it needs
 * `catalog.publish`; switching it off takes it away from them, so it needs
 * `catalog.unpublish`. Checked here as well as at the controller. The move is
 * read from {@see ProductStatus} and written to the
 * same append-only history as the lifecycle, under its own axis.
 */
class SetSalesChannel
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CatalogRefused
     */
    public function handle(User $actor, Product $product, SalesChannel $channel, bool $enable, ?string $reason = null): Product
    {
        if (! CatalogPolicy::canSetChannel($actor, $enable)) {
            throw new AuthorizationException("You may not switch {$channel->label()} ".($enable ? 'on' : 'off').'.');
        }

        return $this->database->transaction(function () use ($actor, $product, $channel, $enable, $reason) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $from = $locked->channelStatus($channel);
            $to = $enable ? $channel->enabled() : $channel->disabled();

            if (! in_array($to, $from->transitionsTo(), true)) {
                throw CatalogRefused::channelUnchanged($channel->label(), $enable);
            }

            $locked->setAttribute($channel->column(), $to);
            $locked->save();

            ProductStatusChange::create([
                'product_id' => $locked->id,
                'axis' => $channel->value,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_channel_changed',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: [$channel->column() => $from->value],
                after: [$channel->column() => $to->value],
                module: 'catalog',
            ));

            return $locked;
        });
    }
}
