<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own products — "My Products" (D25, P13-13, P13-14).
 *
 * Every Supplier Offer the signed-in Supplier holds, whether it came from an
 * approved listing or was assigned by staff: one list, one record. Scoped
 * through the authenticated Supplier's own `offers()` relation, so one Supplier
 * can never see — let alone reach — another's offer, its price, or its stock.
 *
 * What the Supplier sees is its own **Supplier Base Price** — what it agreed to
 * be paid per unit. **The platform's rates are never shown here**: the
 * Platform Rate, wholesale, minimum, maximum and suggested selling prices are
 * Banij's own, and a Supplier can neither see nor change them (D25).
 */
class OfferController extends Controller
{
    public function __construct(protected ProductMediaStore $media) {}

    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $offers = $supplier->offers()
            ->with(['product:id,public_id,name,slug,sku', 'variant.values', 'stock'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $images = $this->images($offers->getCollection());

        $offers->through(fn (SupplierOffer $offer) => $this->row($offer, $images[$offer->product_id] ?? null));

        return Inertia::render('supplier/offers/index', ['offers' => $offers]);
    }

    public function show(Request $request, string $offer): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $model = $supplier->offers()->with(['product', 'variant.values', 'stock', 'priceHistory'])->where('public_id', $offer)->firstOrFail();

        $images = $this->images(collect([$model]));

        return Inertia::render('supplier/offers/show', [
            'offer' => [
                ...$this->row($model, $images[$model->product_id] ?? null),
                'rate_history' => $model->priceHistory->map(fn ($change) => [
                    'supplier_rate' => $change->supplier_rate->jsonSerialize(),
                    'effective_from' => $change->effective_from->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * The first image of each product, in one query.
     *
     * @param  Collection<int, SupplierOffer>  $offers
     * @return array<int, array{url: string, alt: string|null}>
     */
    protected function images($offers): array
    {
        return ProductMedia::query()
            ->whereIn('product_id', $offers->pluck('product_id')->unique()->all())
            ->where('type', ProductMedia::TYPE_IMAGE)
            ->where('position', 1)
            ->get()
            ->mapWithKeys(fn (ProductMedia $item) => [$item->product_id => [
                'url' => $this->media->url($item->path),
                'alt' => $item->alt_text,
            ]])
            ->all();
    }

    /**
     * @param  array{url: string, alt: string|null}|null  $image
     * @return array<string, mixed>
     */
    protected function row(SupplierOffer $offer, ?array $image): array
    {
        return [
            'id' => $offer->public_id,
            'reference' => $offer->reference,
            'product_name' => $offer->product->name,
            'product_slug' => $offer->product->slug,
            'bpc' => $offer->product->sku,
            'image' => $image,
            'variant' => $offer->variant === null ? null : [
                'label' => $offer->variant->label(),
                'sku' => $offer->variant->sku,
            ],
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'is_preferred' => $offer->is_preferred,
            'supplier_rate' => $offer->supplier_rate->jsonSerialize(),
            'supply_mode' => $offer->supply_mode->value,
            'supply_mode_label' => $offer->supply_mode->label(),
            'available_quantity' => $offer->stock?->quantity,
            'fulfilment_capacity' => $offer->fulfilment_capacity,
            'lead_time_days' => $offer->lead_time_days,
            'origin' => $offer->origin->value,
            'origin_label' => $offer->origin->label(),
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }
}
