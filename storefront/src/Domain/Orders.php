<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\App;
use Storefront\Erp\ErpException;
use Storefront\Support\Db;

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
    /**
     * Keep what the ERP said about an order just placed.
     *
     * @param  array<string, mixed>  $order  the ERP's order payload
     * @param  array<string, mixed>  $checkout  the checkout it came from
     * @return array<string, mixed> the local row
     */
    public static function remember(array $order, array $checkout): array
    {
        $publicId = (string) ($order['id'] ?? '');
        $customer = (array) $checkout['customer_json'];
        $now = Db::now();
        $total = is_array($order['totals']['grand_total'] ?? null) ? $order['totals']['grand_total'] : [];

        Db::exec(
            'INSERT INTO orders (public_id, reference, storefront_order_reference, access_token, customer_name, customer_mobile,
                                 customer_email, status, payment_method, grand_total, currency, placed_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), grand_total = VALUES(grand_total), updated_at = VALUES(updated_at)',
            [
                $publicId,
                (string) ($order['reference'] ?? ''),
                (string) $checkout['reference'],
                bin2hex(random_bytes(20)),
                (string) $customer['name'],
                self::digits((string) $customer['phone']),
                $customer['email'] ?? null,
                (string) ($order['status'] ?? 'unknown'),
                (string) $checkout['payment_method'],
                (string) ($total['amount'] ?? '0'),
                (string) ($total['currency'] ?? 'BDT'),
                self::timestamp($order['placed_at'] ?? null),
                $now,
            ],
        );

        return (array) self::find($publicId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $publicId): ?array
    {
        return Db::one('SELECT * FROM orders WHERE public_id = ?', [$publicId]);
    }

    /**
     * The order the signed-in-by-possession visitor may see, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function authorised(string $publicId, ?string $token, ?string $sessionGrant): ?array
    {
        $order = self::find($publicId);

        if ($order === null) {
            return null;
        }

        $byToken = $token !== null && $token !== '' && hash_equals((string) $order['access_token'], $token);
        $bySession = $sessionGrant !== null && hash_equals((string) $order['access_token'], $sessionGrant);

        return $byToken || $bySession ? $order : null;
    }

    /**
     * Find an order by its reference and the mobile number it was placed with.
     *
     * A miss and a wrong number are indistinguishable.
     *
     * @return array<string, mixed>|null
     */
    public static function lookup(string $reference, string $mobile): ?array
    {
        $reference = trim($reference);
        $digits = self::digits($mobile);

        if ($reference === '' || strlen($digits) < 10) {
            return null;
        }

        $order = Db::one(
            'SELECT * FROM orders WHERE reference = ? OR storefront_order_reference = ?',
            [$reference, $reference],
        );

        if ($order === null) {
            return null;
        }

        // The last ten digits are what identifies a Bangladeshi number whether
        // it was typed +8801…, 8801… or 01….
        $stored = substr((string) $order['customer_mobile'], -10);

        return hash_equals($stored, substr($digits, -10)) ? $order : null;
    }

    /**
     * The order as the ERP has it now; the local copy follows.
     *
     * @return array<string, mixed>|null null when the ERP does not know it
     *
     * @throws ErpException when the ERP cannot be asked
     */
    public static function fetch(string $publicId): ?array
    {
        try {
            $response = App::erp()->get('/orders/'.rawurlencode($publicId));
        } catch (ErpException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        $order = is_array($response['data'] ?? null) ? $response['data'] : $response;

        if (isset($order['status']) && is_string($order['status'])) {
            Db::exec('UPDATE orders SET status = ?, updated_at = ? WHERE public_id = ?', [$order['status'], Db::now(), $publicId]);
        }

        return $order;
    }

    /**
     * An ERP status change arrived by webhook: the local copy follows.
     */
    public static function noteStatus(string $publicId, string $status): void
    {
        Db::exec('UPDATE orders SET status = ?, updated_at = ? WHERE public_id = ?', [$status, Db::now(), $publicId]);
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
