<?php

namespace App\Services;

use App\Erp\Client;
use App\Erp\ErpException;
use App\Models\Checkout;
use App\Models\Order;

/**
 * Orders as this storefront keeps and reads them.
 *
 * The local row exists so a customer can find their order again and so access
 * can be checked without asking the ERP. What an order *is* — its status, its
 * payment, its timeline — is always read back from the ERP, which is the only
 * place that knows (contract §5.3).
 *
 * **Possessing a mobile number grants nothing** (§6.2). An order is reached
 * through its own unguessable access token, or through a lookup that needs the
 * order reference *and* the number it was placed with, rate-limited.
 */
final class Orders
{
    public function __construct(private readonly Client $erp) {}

    /**
     * Keep what the ERP said about an order just placed.
     *
     * @param  array<string, mixed>  $order  the ERP's order payload
     */
    public function remember(array $order, Checkout $checkout): Order
    {
        $publicId = (string) ($order['id'] ?? '');
        $customer = $checkout->customer_json;
        $total = is_array($order['totals']['grand_total'] ?? null) ? $order['totals']['grand_total'] : [];

        $existing = Order::query()->where('public_id', $publicId)->first();

        if ($existing !== null) {
            $existing->update([
                'status' => (string) ($order['status'] ?? 'unknown'),
                'grand_total' => (string) ($total['amount'] ?? '0'),
            ]);

            return $existing;
        }

        return Order::query()->create([
            'public_id' => $publicId,
            'reference' => (string) ($order['reference'] ?? ''),
            'storefront_order_reference' => (string) $checkout->reference,
            'access_token' => bin2hex(random_bytes(20)),
            'customer_name' => (string) $customer['name'],
            'customer_mobile' => self::digits((string) $customer['phone']),
            'customer_email' => $customer['email'] ?? null,
            'status' => (string) ($order['status'] ?? 'unknown'),
            'payment_method' => $checkout->payment_method,
            'grand_total' => (string) ($total['amount'] ?? '0'),
            'currency' => (string) ($total['currency'] ?? 'BDT'),
            'placed_at' => self::timestamp($order['placed_at'] ?? null),
        ]);
    }

    public function find(string $publicId): ?Order
    {
        return Order::query()->where('public_id', $publicId)->first();
    }

    /**
     * The order the visitor may see, or null: they hold its token, or placed it in this session.
     */
    public function authorised(string $publicId, ?string $token, ?string $sessionGrant): ?Order
    {
        $order = $this->find($publicId);

        if ($order === null) {
            return null;
        }

        $byToken = $token !== null && $token !== '' && hash_equals($order->access_token, $token);
        $bySession = $sessionGrant !== null && hash_equals($order->access_token, $sessionGrant);

        return $byToken || $bySession ? $order : null;
    }

    /**
     * Find an order by its reference and the mobile number it was placed with.
     *
     * A miss and a wrong number are indistinguishable.
     */
    public function lookup(string $reference, string $mobile): ?Order
    {
        $reference = trim($reference);
        $digits = self::digits($mobile);

        if ($reference === '' || strlen($digits) < 10) {
            return null;
        }

        $order = Order::query()
            ->where('reference', $reference)
            ->orWhere('storefront_order_reference', $reference)
            ->first();

        if ($order === null) {
            return null;
        }

        // The last ten digits are what identifies a Bangladeshi number whether
        // it was typed +8801…, 8801… or 01….
        $stored = substr($order->customer_mobile, -10);

        return hash_equals($stored, substr($digits, -10)) ? $order : null;
    }

    /**
     * The order as the ERP has it now; the local copy follows.
     *
     * @return array<string, mixed>|null null when the ERP does not know it
     *
     * @throws ErpException when the ERP cannot be asked
     */
    public function fetch(string $publicId): ?array
    {
        try {
            $response = $this->erp->get('/orders/'.rawurlencode($publicId));
        } catch (ErpException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        $order = is_array($response['data'] ?? null) ? $response['data'] : $response;

        if (isset($order['status']) && is_string($order['status'])) {
            $this->noteStatus($publicId, $order['status']);
        }

        return $order;
    }

    /**
     * An ERP status change arrived by webhook: the local copy follows.
     */
    public function noteStatus(string $publicId, string $status): void
    {
        Order::query()->where('public_id', $publicId)->update(['status' => $status, 'updated_at' => now()]);
    }

    public static function digits(string $mobile): string
    {
        return preg_replace('/\D/', '', $mobile) ?? '';
    }

    private static function timestamp(mixed $value): string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return gmdate('Y-m-d H:i:s', $time === false ? time() : $time);
    }
}
