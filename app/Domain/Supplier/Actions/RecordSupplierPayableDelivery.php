<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * The explicit delivery event this batch stands in for fulfilment with (D25,
 * P13-22).
 *
 * Fulfilment integration is not built yet, so nothing marks a Supplier-backed
 * order line delivered on its own — a person records it happened, the same way
 * {@see ReceiveReturnedItems} records a return
 * arriving. When fulfilment lands, its handover event calls this same action
 * instead of a person, and nothing else here changes.
 *
 * Needs `order.edit`: recording a delivery is an order-fulfilment fact, exactly
 * the permission {@see ReceiveReturnedItems} asks for
 * the equivalent step on a return.
 */
class RecordSupplierPayableDelivery
{
    public function __construct(
        protected EvaluateSupplierPayableEligibility $eligibility,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderItem $item, ?CarbonImmutable $at = null): SupplierPayable
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit))) {
            throw new AuthorizationException('Recording a Supplier line as delivered needs order.edit.');
        }

        /** @var SupplierPayable|null $payable */
        $payable = SupplierPayable::query()->where('order_item_id', $item->id)->first();

        if ($payable === null) {
            throw new RuntimeException('This order line has no Supplier payable to mark delivered.');
        }

        return $this->eligibility->markDelivered($payable, $at);
    }
}
