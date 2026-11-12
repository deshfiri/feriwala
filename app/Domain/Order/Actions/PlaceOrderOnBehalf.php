<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\PriceWebsiteOrder;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Models\Website;
use App\Models\User;
use App\Support\Localization\MobileNumber;
use Illuminate\Support\Str;

/**
 * A Partner takes an order for one of their own customers — by phone, in a
 * shop, in a chat — and puts it on their storefront's books.
 *
 * It is the storefront's own intake, not a second one: the order is priced by
 * the ERP from the website's selling prices ({@see PriceWebsiteOrder}), the
 * figures the Partner saw are the figures it is held to, and it goes through
 * {@see PlaceWebsiteOrder} — the same checks, the same stock hold, the same
 * payment record. It is always cash on delivery: Feriwala is merchant of
 * record, and an online payment needs the customer's own browser.
 *
 * Nothing here chooses a price. The Partner names what and how many.
 */
class PlaceOrderOnBehalf
{
    public function __construct(
        protected PlaceWebsiteOrder $orders,
        protected PriceWebsiteOrder $pricing,
        protected MobileNumber $mobiles,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * What the order would cost, without taking it.
     *
     * @param  array<int, array{sku: string, quantity: int}>  $items
     *
     * @throws WebsiteOrderRefused
     */
    public function quote(Website $website, array $items): WebsiteOrderQuote
    {
        return $this->pricing->quote($website, $this->normalised($items));
    }

    /**
     * @param  array{name: string, mobile: string, email?: string|null}  $customer
     * @param  array<string, string|null>  $shippingAddress
     * @param  array<int, array{sku: string, quantity: int}>  $items
     * @return array{0: Order, 1: bool} the order, and whether this call placed it
     *
     * @throws WebsiteOrderRefused
     */
    public function handle(
        Website $website,
        User $actor,
        array $customer,
        array $shippingAddress,
        array $items,
        string $idempotencyKey,
        ?string $note = null,
    ): array {
        $mobile = $this->mobiles->normalise($customer['mobile']);

        if ($mobile === null) {
            throw WebsiteOrderRefused::invalidMobileNumber();
        }

        $items = $this->normalised($items);
        $quote = $this->pricing->quote($website, $items);

        // The figures come from the ERP's own quote, so the claim the intake
        // checks is the price the Partner was shown a moment ago.
        $submission = new WebsiteOrderSubmission(
            // Derived from the key, so the same form submitted twice is one order.
            reference: 'PO-'.Str::upper(substr(hash('sha256', $idempotencyKey), 0, 10)),
            idempotencyKey: $idempotencyKey,
            customer: new WebsiteCustomerDetails(
                name: trim($customer['name']),
                mobile: $mobile,
                email: $customer['email'] ?? null,
                isGuest: true,
            ),
            shippingAddress: $shippingAddress,
            billingAddress: $shippingAddress,
            items: $items,
            claimedUnitPrices: array_map(fn ($line) => $line->unitPrice, $quote->lines),
            claimedTotals: [
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'shipping' => $quote->delivery,
                'tax' => $quote->tax->addedTotal(),
                'grand_total' => $quote->total,
            ],
            paymentMethod: 'cod',
            customerNote: $note,
        );

        [$order, $placed] = $this->orders->handle($website, $submission);

        if ($placed) {
            $this->audit->handle(new AuditEntry(
                action: 'order.placed_on_behalf',
                actorId: $actor->id,
                auditableType: Order::class,
                auditableId: $order->id,
                reason: 'Taken for a customer by the partner.',
                accountId: $website->business_account_id,
                module: 'order',
            ));
        }

        return [$order, $placed];
    }

    /**
     * One line per SKU, upper-cased, quantities as integers.
     *
     * @param  array<int, array{sku: string, quantity: int|string}>  $items
     * @return array<int, array{sku: string, quantity: int}>
     */
    protected function normalised(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $sku = mb_strtoupper(trim($item['sku']));

            $merged[$sku] = ($merged[$sku] ?? 0) + (int) $item['quantity'];
        }

        $lines = [];

        foreach ($merged as $sku => $quantity) {
            $lines[] = ['sku' => (string) $sku, 'quantity' => $quantity];
        }

        return $lines;
    }
}
