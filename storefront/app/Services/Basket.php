<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A visitor's basket, kept in the database against a cookie token.
 *
 * It remembers *what* is wanted — a SKU and a quantity, with the name and picture
 * to show — and never a price. The price of a line is whatever the ERP says when
 * the cart is quoted (§6.0), so a stale basket cannot be a stale charge.
 */
final class Basket
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

        return CartItem::query()
            ->where('cart_id', $id)
            ->orderBy('created_at')
            ->orderBy('sku')
            ->get()
            ->map(static fn (CartItem $row): array => [
                'sku' => (string) $row->sku,
                'product_id' => (string) $row->product_id,
                'slug' => (string) $row->product_slug,
                'name' => (string) $row->product_name,
                'variant' => $row->variant_label === null ? null : (string) $row->variant_label,
                'image' => $row->image_url === null ? null : (string) $row->image_url,
                'quantity' => (int) $row->quantity,
            ])
            ->all();
    }

    public function count(): int
    {
        $id = $this->existingId();

        return $id === null ? 0 : (int) CartItem::query()->where('cart_id', $id)->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    /**
     * Add a line, or raise the quantity of one already there.
     *
     * @param  array{product_id: string, slug: string, name: string, variant: string|null, image: string|null}  $meta
     *
     * @throws DomainException when the basket already holds as many lines as it may
     */
    public function add(string $sku, int $quantity, array $meta): void
    {
        $sku = self::sku($sku);
        $quantity = max(1, min(self::MAX_QUANTITY, $quantity));
        $id = $this->ensure();

        DB::transaction(function () use ($id, $sku, $quantity, $meta): void {
            $others = CartItem::query()->where('cart_id', $id)->where('sku', '!=', $sku)->count();

            if ($others >= self::MAX_LINES) {
                throw new DomainException('cart_full');
            }

            $attributes = [
                'product_name' => mb_substr($meta['name'], 0, 255),
                'variant_label' => $meta['variant'] === null ? null : mb_substr($meta['variant'], 0, 255),
                'image_url' => $meta['image'] === null ? null : mb_substr($meta['image'], 0, 1024),
            ];

            $line = CartItem::query()->where('cart_id', $id)->where('sku', $sku);
            $existing = (clone $line)->lockForUpdate()->first();

            if ($existing === null) {
                CartItem::query()->create([
                    'cart_id' => $id,
                    'sku' => $sku,
                    'product_id' => $meta['product_id'],
                    'product_slug' => $meta['slug'],
                    'quantity' => $quantity,
                ] + $attributes);

                return;
            }

            $line->update(['quantity' => min(self::MAX_QUANTITY, (int) $existing->quantity + $quantity)] + $attributes);
        });

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

        CartItem::query()
            ->where('cart_id', $id)
            ->where('sku', self::sku($sku))
            ->update(['quantity' => min(self::MAX_QUANTITY, $quantity)]);

        $this->touch($id);
    }

    public function remove(string $sku): void
    {
        $id = $this->existingId();

        if ($id !== null) {
            CartItem::query()->where('cart_id', $id)->where('sku', self::sku($sku))->delete();
            $this->touch($id);
        }
    }

    public function clear(): void
    {
        $id = $this->existingId();

        if ($id !== null) {
            CartItem::query()->where('cart_id', $id)->delete();
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
     * The token for the visitor's cookie, creating the basket on first need.
     */
    public function ensureToken(): string
    {
        $this->ensure();

        return (string) $this->token;
    }

    public static function prune(int $olderThanDays = 30): int
    {
        return Cart::query()->where('updated_at', '<', now()->subDays($olderThanDays))->delete();
    }

    private function existingId(): ?int
    {
        if ($this->id !== null) {
            return $this->id;
        }

        if ($this->token === null) {
            return null;
        }

        $id = Cart::query()->where('token', $this->token)->value('id');

        return $this->id = $id === null ? null : (int) $id;
    }

    private function ensure(): int
    {
        $existing = $this->existingId();

        if ($existing !== null) {
            return $existing;
        }

        $cart = Cart::query()->create(['token' => bin2hex(random_bytes(20))]);
        $this->token = $cart->token;

        return $this->id = $cart->id;
    }

    private function touch(int $id): void
    {
        Cart::query()->whereKey($id)->update(['updated_at' => now()]);
    }
}
