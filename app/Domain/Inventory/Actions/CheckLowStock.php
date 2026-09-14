<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Inventory\Models\StockItem;
use App\Models\User;
use App\Notifications\Inventory\StockRunningLow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Tell inventory staff when a SKU in a warehouse falls to its low-stock threshold
 * (§19: low-stock notifications, P3-29).
 *
 * **Once per shortfall.** The item remembers that the alert went out, so a figure
 * that sits below its threshold does not notify on every movement; it is ready to
 * alert again once the figure has risen back above the threshold. The check runs
 * under the item's row lock, so two movements finishing together cannot both
 * decide the alert is theirs to send.
 *
 * The recipients are the people who can act on it: active platform staff who may
 * view inventory, and the Super Admin. Nobody who belongs to a business account
 * is told about central stock, whatever they have been handed.
 */
class CheckLowStock
{
    /**
     * @return bool whether an alert was sent
     */
    public function handle(int $stockItemId): bool
    {
        $alert = DB::transaction(function () use ($stockItemId): ?StockRunningLow {
            /** @var StockItem|null $item */
            $item = StockItem::query()
                ->with(['warehouse:id,code,name', 'product:id,sku', 'variant:id,sku'])
                ->lockForUpdate()
                ->find($stockItemId);

            if ($item === null) {
                return null;
            }

            if (! $item->isLow()) {
                // Recovered, or nobody is watching: ready to alert next time.
                if ($item->low_stock_alerted_at !== null) {
                    $this->markAlerted($item, false);
                }

                return null;
            }

            if ($item->low_stock_alerted_at !== null) {
                return null;
            }

            $this->markAlerted($item, true);

            return new StockRunningLow(
                stockItemId: $item->public_id,
                sku: $item->sku(),
                warehouse: $item->warehouse->code,
                available: $item->available,
                threshold: (int) $item->low_stock_threshold,
            );
        });

        if ($alert === null) {
            return false;
        }

        Notification::send($this->recipients(), $alert);

        return true;
    }

    /**
     * Written straight to the row, so remembering an alert does not move the
     * item's `updated_at` — which availability reports as when the stock changed.
     */
    protected function markAlerted(StockItem $item, bool $alerted): void
    {
        DB::table('stock_items')->where('id', $item->id)->update([
            'low_stock_alerted_at' => $alerted ? now() : null,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    protected function recipients(): Collection
    {
        $permission = PermissionCatalogue::name(PermissionModule::Inventory, PermissionAction::View);

        return User::permission($permission)->get()
            ->merge(User::role(PlatformRole::SuperAdmin->value)->get())
            ->unique('id')
            ->filter(fn (User $user) => $user->hasPlatformAccess() && ! CatalogPolicy::isBusinessIdentity($user))
            ->values();
    }
}
