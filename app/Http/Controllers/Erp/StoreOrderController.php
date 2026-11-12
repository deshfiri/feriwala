<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Cases\Queries\CaseLinks;
use App\Domain\Cases\Queries\PartnerCasePayload;
use App\Domain\Courier\Queries\StoreOrderCourierPanel;
use App\Domain\Damage\Queries\DamageReportEligibility;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\PlaceOrderOnBehalf;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Exceptions\ConfirmationCodesExhausted;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\CreatableStorefronts;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Order\Queries\StoreOrderIndex;
use App\Domain\Order\Queries\StoreOrderTimeline;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PlaceStoreOrderRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Partner's Store Orders: every order from their own storefronts, in one list.
 *
 * Read-only apart from cancelling an order that is still Pending. A Partner
 * never advances a status — confirming, packing and dispatching are the
 * platform's actions — and never reaches another Partner's order: every query
 * here starts from their own account.
 */
class StoreOrderController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected StoreOrderIndex $index,
        protected StoreOrderTimeline $timeline,
        protected CancelStoreOrder $cancel,
        protected PartnerCasePayload $cases,
        protected ReturnEligibility $eligibility,
        protected CaseLinks $links,
        protected DamageReportEligibility $damageEligibility,
        protected StoreOrderCourierPanel $courier,
        protected PlaceOrderOnBehalf $place,
        protected ConfirmCodOrder $codConfirmation,
        protected SendCodConfirmationCode $codes,
        protected CreatableStorefronts $creatable,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $orders = $this->index->query($request, $account->id)
            ->paginate(StoreOrderIndex::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => $this->index->row($order, forStaff: false));

        return Inertia::render('store-orders/index', [
            'orders' => $orders,
            'filters' => $request->only(['storefront', 'step', 'payment', 'from', 'to', 'search']),
            'steps' => array_map(fn (StoreOrderStep $step) => ['value' => $step->value, 'label' => $step->label()], StoreOrderStep::cases()),
            'storefronts' => Website::query()->where('business_account_id', $account->id)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Website $website) => ['value' => $website->public_id, 'label' => $website->name])
                ->all(),
            // Only the storefronts this person may run can take an order for a customer.
            'creatable_storefronts' => $request->user() instanceof User ? $this->creatable->for($request->user(), $account->id) : [],
            'create_urls' => $this->creatable->urls(),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $record = $this->own($request, $order);
        $record->load(['items', 'payment', 'website:id,public_id,name']);

        $timeline = $this->timeline->for($record);

        return Inertia::render('store-orders/show', [
            'order' => $this->index->row($record, forStaff: false) + [
                'customer_phone' => $record->customer['mobile'] ?? null,
                'shipping_address' => $record->shipping_address,
                'cancellation_reason' => $record->cancellation_reason,
                'items' => $record->items->map(fn ($item) => [
                    'sku' => $item->sku,
                    'name' => $item->product_name,
                    'variant' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'line_total' => $item->line_total->jsonSerialize(),
                ])->all(),
            ],
            'urls' => [
                'back' => route('store-orders.index'),
                'cancel' => route('store-orders.cancel', $record->public_id),
                'advance' => null,
                'request_case' => route('cases.create', $record->public_id),
                'report_damage' => route('damages.create', $record->public_id),
                'confirm' => route('store-orders.confirm', $record->public_id),
                'resend_code' => route('store-orders.resend-code', $record->public_id),
            ],
            // The Partner reads the whole timeline; they get no next-action button.
            'timeline' => ['next_action' => null] + $timeline,
            // Customer-safe: the courier, its reference and Banij's own status.
            'courier' => $this->courier->forPartner($record),
            // Every return and exchange asked for on this order (D35).
            'cases' => $this->cases->forOrder($record),
            'exchange_origin' => $this->links->exchangeOriginForPartner($record),
            'can' => [
                'advance' => false,
                'cancel' => StoreOrderStep::for($record->status) === StoreOrderStep::Pending && ! $timeline['cancelled'],
                'request_case' => $this->mayRequestCase($request, $record),
                'report_damage' => $this->mayReportDamage($request, $record),
                'confirm_with_code' => $record->status === OrderStatus::CustomerVerificationPending && $this->mayManageOrderStore($request, $record),
            ],
        ]);
    }

    /**
     * The storefront's products a customer can be sold, for the "new order" form's picker.
     */
    public function products(Request $request): JsonResponse
    {
        $website = $this->managedWebsite($request, (string) $request->query('storefront'));
        $search = trim((string) $request->query('search', ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $selections = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->published()
            ->whereHas('product', fn ($query) => $query->when(
                $search !== '',
                fn ($products) => $products->where(fn ($match) => $match->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like)),
            ))
            ->with(['product.variants' => fn ($variants) => $variants->where('is_active', true)->with('values')])
            ->latest('id')
            ->limit(40)
            ->get();

        $options = [];

        foreach ($selections as $selection) {
            $price = $selection->sellingPrice();
            $product = $selection->product;

            if ($price === null) {
                continue;
            }

            if ($product->variants->isEmpty()) {
                $options[] = ['sku' => $product->sku, 'label' => $product->name, 'price' => $price->jsonSerialize()];

                continue;
            }

            foreach ($product->variants as $variant) {
                $options[] = [
                    'sku' => $variant->sku,
                    'label' => $product->name.' — '.$variant->values->pluck('value')->implode(' / '),
                    'price' => $price->jsonSerialize(),
                ];
            }
        }

        return new JsonResponse(['data' => $options]);
    }

    /**
     * What an order would cost, priced by the ERP — the form shows it and never works it out.
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storefront' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $website = $this->managedWebsite($request, $validated['storefront']);

        try {
            $quote = $this->place->quote($website, $validated['items']);
        } catch (WebsiteOrderRefused $refused) {
            throw ValidationException::withMessages(['items' => $refused->getMessage()]);
        }

        return new JsonResponse(['data' => [
            'lines' => array_map(fn ($line) => [
                'sku' => $line->sku,
                'name' => $line->product->name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice->jsonSerialize(),
                'line_total' => $line->total()->jsonSerialize(),
            ], $quote->lines),
            'subtotal' => $quote->subtotal->jsonSerialize(),
            'delivery' => $quote->delivery->jsonSerialize(),
            'tax' => $quote->tax->addedTotal()->jsonSerialize(),
            'total' => $quote->total->jsonSerialize(),
        ]]);
    }

    /**
     * Take an order for one of the Partner's own customers (cash on delivery).
     */
    public function store(PlaceStoreOrderRequest $request): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $validated = $request->validated();
        $website = $this->managedWebsite($request, $validated['storefront']);

        try {
            [$order] = $this->place->handle(
                $website,
                $actor,
                [
                    'name' => $validated['customer_name'],
                    'mobile' => $validated['customer_phone'],
                    'email' => $validated['customer_email'] ?? null,
                ],
                [
                    'line1' => $validated['line1'],
                    'line2' => $validated['line2'] ?? null,
                    'city' => $validated['city'],
                    'district' => $validated['district'] ?? null,
                    'postcode' => $validated['postcode'] ?? null,
                    'country' => 'BD',
                ],
                $validated['items'],
                $validated['token'],
                $validated['note'] ?? null,
            );
        } catch (WebsiteOrderRefused $refused) {
            throw ValidationException::withMessages([
                $refused->errorCode === 'invalid_mobile_number' ? 'customer_phone' : 'items' => $refused->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.create.placed')]);

        return to_route('store-orders.show', $order->public_id);
    }

    /**
     * The customer reads the Partner the code they were sent, and the Partner
     * enters it to confirm their cash-on-delivery order.
     */
    public function confirm(Request $request, string $order): RedirectResponse
    {
        $record = $this->own($request, $order);
        $this->assertMayManage($request, $record);

        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        try {
            $this->codConfirmation->handle($record, trim($validated['code']));
        } catch (WebsiteOrderRefused $refused) {
            throw ValidationException::withMessages(['code' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.create.confirmed')]);

        return back();
    }

    /**
     * Send the customer another confirmation code.
     */
    public function resendCode(Request $request, string $order): RedirectResponse
    {
        $record = $this->own($request, $order);
        $this->assertMayManage($request, $record);

        abort_if($record->status !== OrderStatus::CustomerVerificationPending, 409);

        try {
            $this->codes->handle($record);
        } catch (ResendTooSoon $tooSoon) {
            throw ValidationException::withMessages(['code' => __('store_orders.create.resend_wait', ['seconds' => $tooSoon->secondsRemaining])]);
        } catch (ConfirmationCodesExhausted) {
            throw ValidationException::withMessages(['code' => __('store_orders.create.resend_exhausted')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.create.code_sent')]);

        return back();
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $record = $this->own($request, $order);
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->cancel->handle($record, $actor, $validated['reason'], asPartner: true);
        } catch (StoreOrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.cancelled')]);

        return back();
    }

    /**
     * Whether this person may ask for a return or an exchange on this order: it
     * can still come back, and they may manage the store it came through.
     */
    protected function mayRequestCase(Request $request, Order $order): bool
    {
        $user = $request->user();
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $user instanceof User
            && $website !== null
            && $this->eligibility->for($order)['eligible']
            && Gate::forUser($user)->allows('manage', $website);
    }

    /**
     * Whether this person may report a damaged item on this order: something on it
     * can still be reported, and they manage the store it came through.
     */
    protected function mayReportDamage(Request $request, Order $order): bool
    {
        $user = $request->user();
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $user instanceof User
            && $website !== null
            && $this->damageEligibility->for($order)['eligible']
            && Gate::forUser($user)->allows('manage', $website);
    }

    /**
     * One of this Partner's own storefronts they may run, or a 404 / 403.
     */
    protected function managedWebsite(Request $request, string $publicId): Website
    {
        $account = $this->businessAccountFor($request);

        /** @var Website|null $website */
        $website = Website::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $publicId)
            ->first();

        abort_if($website === null, 404);

        $user = $request->user();

        abort_unless($user instanceof User && Gate::forUser($user)->allows('manage', $website), 403);

        return $website;
    }

    /**
     * Whoever may run the storefront an order came through may confirm it.
     */
    protected function assertMayManage(Request $request, Order $order): void
    {
        abort_unless($this->mayManageOrderStore($request, $order), 403);
    }

    protected function mayManageOrderStore(Request $request, Order $order): bool
    {
        $user = $request->user();
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $user instanceof User && $website !== null && Gate::forUser($user)->allows('manage', $website);
    }

    /**
     * The order, if it is a Store Order of this Partner's account — a 404 otherwise.
     * A replacement order an exchange sent is one of them: it was sent from the same store.
     */
    protected function own(Request $request, string $publicId): Order
    {
        $account = $this->businessAccountFor($request);

        /** @var Order|null $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->whereIn('source', [OrderSource::Website->value, OrderSource::Exchange->value])
            ->where('business_account_id', $account->id)
            ->first();

        abort_if($order === null, 404);

        return $order;
    }
}
