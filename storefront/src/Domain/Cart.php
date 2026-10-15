<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\Support\Db;

/**
 * A visitor's basket, kept in MySQL against a cookie token.
 *
 * It remembers *what* is wanted — a SKU and a quantity, with the name and picture
 * to show — and never a price. The price of a line is whatever the ERP says when
 * the cart is quoted (§6.0), so a stale basket cannot be a stale charge.
 */
final class Cart
{
    public const COOKIE = 'sf_cart';

    public const MAX_LINES = 50;

    public const MAX_QUANTITY = 1000;

    private ?int $id = null;

    private function __construct(private ?string $token) {}

    public static function forToken(?string $token): self
    {
        return new self($token !== null && preg_match('/^[0-9a-f]{40}$/', $token) === 1 ? $token : null);
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * @return list<array{sku: string, product_id: string, slug: string, name: string, variant: string|null, image: string|null, quantity: int}>
     */
    public function items(): array
    {
        $id = $this->existingId();

        if ($id === null) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'sku' => (string) $row['sku'],
            'product_id' => (string) $row['product_id'],
            'slug' => (string) $row['product_slug'],
            'name' => (string) $row['product_name'],
            'variant' => $row['variant_label'] === null ? null : (string) $row['variant_label'],
            'image' => $row['image_url'] === null ? null : (string) $row['image_url'],
            'quantity' => (int) $row['quantity'],
        ], Db::all('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY created_at, sku', [$id]));
    }

    public function count(): int
    {
        $id = $this->existingId();

        return $id === null ? 0 : (int) Db::value('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ?', [$id]);
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    /**
     * Add a line, or raise the quantity of one already there.
     *
     * @param  array{product_id: string, slug: string, name: string, variant: string|null, image: string|null}  $meta
     */
    public function add(string $sku, int $quantity, array $meta): void
    {
        $sku = self::sku($sku);
        $quantity = max(1, min(self::MAX_QUANTITY, $quantity));
        $id = $this->ensure();
        $now = Db::now();

        if (Db::value('SELECT COUNT(*) FROM cart_items WHERE cart_id = ? AND sku <> ?', [$id, $sku]) >= self::MAX_LINES) {
            throw new \DomainException('cart_full');
        }

        Db::exec(
            'INSERT INTO cart_items (cart_id, sku, product_id, product_slug, product_name, variant_label, image_url, quantity, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                quantity = LEAST(?, quantity + VALUES(quantity)),
                product_name = VALUES(product_name), variant_label = VALUES(variant_label),
                image_url = VALUES(image_url), updated_at = VALUES(updated_at)',
            [
                $id, $sku, $meta['product_id'], $meta['slug'], mb_substr($meta['name'], 0, 255),
                $meta['variant'] === null ? null : mb_substr($meta['variant'], 0, 255),
                $meta['image'] === null ? null : mb_substr($meta['image'], 0, 1024),
                $quantity, $now, $now, self::MAX_QUANTITY,
            ],
        );

        $this->touch($id);
    }

    public function setQuantity(string $sku, int $quantity): void
    {
        $id = $this->existingId();

        if ($id === null) {
            return;
        }

        if ($quantity <= 0) {
            $this->remove($sku);

            return;
        }

        Db::exec(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE cart_id = ? AND sku = ?',
            [min(self::MAX_QUANTITY, $quantity), Db::now(), $id, self::sku($sku)],
        );

        $this->touch($id);
    }

    public function remove(string $sku): void
    {
        $id = $this->existingId();

        if ($id !== null) {
            Db::exec('DELETE FROM cart_items WHERE cart_id = ? AND sku = ?', [$id, self::sku($sku)]);
            $this->touch($id);
        }
    }

    public function clear(): void
    {
        $id = $this->existingId();

        if ($id !== null) {
            Db::exec('DELETE FROM cart_items WHERE cart_id = ?', [$id]);
        }
    }

    public function id(): ?int
    {
        return $this->existingId();
    }

    /**
     * The ERP's own SKU form: trimmed and upper-cased, exactly as it sends them
     * back and as `POST /orders` reads them.
     */
    public static function sku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }

    /**
     * Cart cookie value, creating the cart on first need.
     */
    public function ensureToken(): string
    {
        $this->ensure();

        return (string) $this->token;
    }

    public static function prune(int $olderThanDays = 30): int
    {
        return Db::exec('DELETE FROM carts WHERE updated_at < ?', [gmdate('Y-m-d H:i:s', time() - $olderThanDays * 86400)]);
    }

    private function existingId(): ?int
    {
        if ($this->id !== null) {
            return $this->id;
        }

        if ($this->token === null) {
            return null;
        }

        $id = Db::value('SELECT id FROM carts WHERE token = ?', [$this->token]);

        return $this->id = $id === null ? null : (int) $id;
    }

    private function ensure(): int
    {
        $existing = $this->existingId();

        if ($existing !== null) {
            return $existing;
        }

        $this->token = bin2hex(random_bytes(20));
        $now = Db::now();

        Db::exec('INSERT INTO carts (token, created_at, updated_at) VALUES (?, ?, ?)', [$this->token, $now, $now]);

        return $this->id = Db::lastId();
    }

    private function touch(int $id): void
    {
        Db::exec('UPDATE carts SET updated_at = ? WHERE id = ?', [Db::now(), $id]);
    }
}
