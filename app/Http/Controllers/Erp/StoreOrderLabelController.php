<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Concerns\RendersShipmentLabels;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A Partner printing the label for a parcel of one of their own Storefront's
 * orders.
 *
 * Self-scoped in the query: the order is looked up *within* the signed-in
 * Partner's account, and the shipment within that order. Anything else --
 * another Partner's order, a shipment of a different order, an id that does
 * not exist -- is the same refusal, so the response says nothing about what
 * exists elsewhere.
 */
class StoreOrderLabelController extends Controller
{
    use RendersShipmentLabels, ResolvesBusinessAccount;

    public function show(Request $request, string $order, string $shipment): Response
    {
        $account = $this->businessAccountFor($request);

        /** @var Order|null $record */
        $record = Order::query()
            ->where('public_id', $order)
            ->where('source', OrderSource::Website->value)
            ->where('business_account_id', $account->id)
            ->first();

        abort_if($record === null, 403);

        /** @var Shipment|null $parcel */
        $parcel = Shipment::query()->where('public_id', $shipment)->where('order_id', $record->id)->first();

        abort_if($parcel === null, 403);

        /** @var ShipmentLabel|null $label */
        $label = ShipmentLabel::query()->current()->where('shipment_id', $parcel->id)->first();

        abort_if($label === null, 404);

        return $this->renderLabels($request, [$label], 'label-'.$parcel->reference);
    }
}
