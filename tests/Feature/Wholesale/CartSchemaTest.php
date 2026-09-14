<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Models\CartItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The wholesale cart's shape (P4-3, §14).
 *
 * One cart per person, reached only through that person and the account they
 * work in; a line per stockable unit with a positive quantity; and a cart filled
 * for another account discarded rather than repriced. The database holds the
 * shape, so a write that skips the application cannot bend it.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->other = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;

    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->shirt = Product::create(['name' => 'Polo shirt', 'sku' => 'FW-SH', 'category_id' => $category->id]);
    $this->medium = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-M', 'combination_key' => 'm']);

    $this->carts = app(OpenCart::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cartSchemaLine(Cart $cart, array $attributes): CartItem
{
    return CartItem::create([
        'cart_id' => $cart->id,
        'quantity' => 1,
        ...$attributes,
    ]);
}

describe('whose cart it is', function () {
    it('opens one cart per person for the account they work in, and returns that same cart after', function () {
        $first = $this->carts->forUser($this->owner, $this->account);
        $again = $this->carts->forUser($this->owner, $this->account);

        expect($again->id)->toBe($first->id)
            ->and($this->carts->find($this->owner, $this->account)?->id)->toBe($first->id)
            ->and(Cart::query()->where('user_id', $this->owner->id)->count())->toBe(1);
    });

    it('gives each member of an account a cart of their own', function () {
        $member = User::factory()->staff()->staffOf($this->account, AccountRole::Staff)->create();

        $mine = $this->carts->forUser($this->owner, $this->account);
        $theirs = $this->carts->forUser($member, $this->account);

        expect($theirs->id)->not->toBe($mine->id)
            ->and($this->carts->find($member, $this->account)?->id)->toBe($theirs->id);
    });

    it('discards a cart left from another account rather than repricing it', function () {
        $stale = Cart::create(['user_id' => $this->owner->id, 'business_account_id' => $this->other->id]);
        cartSchemaLine($stale, ['product_id' => $this->kettle->id, 'quantity' => 4]);

        $cart = $this->carts->forUser($this->owner, $this->account);

        expect($cart->business_account_id)->toBe($this->account->id)
            ->and(Cart::query()->whereKey($stale->id)->exists())->toBeFalse()
            ->and(CartItem::query()->where('cart_id', $stale->id)->exists())->toBeFalse()
            ->and($this->carts->find($this->owner, $this->other))->toBeNull();
    });
});

describe('the database holds the cart\'s shape', function () {
    it('refuses what a cart cannot hold', function (Closure $write) {
        $cart = $this->carts->forUser($this->owner, $this->account);
        cartSchemaLine($cart, ['product_id' => $this->kettle->id]);
        cartSchemaLine($cart, ['product_id' => $this->shirt->id, 'product_variant_id' => $this->medium->id]);

        expect(fn () => DB::transaction(fn () => $write($cart)))->toThrow(QueryException::class);
    })->with([
        'no units' => [fn (Cart $cart) => cartSchemaLine($cart, ['product_id' => test()->shirt->id, 'quantity' => 0])],
        'a variation of another product' => [fn (Cart $cart) => cartSchemaLine($cart, ['product_id' => test()->kettle->id, 'product_variant_id' => test()->medium->id])],
        'the same product twice' => [fn (Cart $cart) => cartSchemaLine($cart, ['product_id' => test()->kettle->id])],
        'the same variation twice' => [fn (Cart $cart) => cartSchemaLine($cart, ['product_id' => test()->shirt->id, 'product_variant_id' => test()->medium->id])],
        'a second cart for the same person' => [fn () => Cart::create(['user_id' => test()->owner->id, 'business_account_id' => test()->other->id])],
        'moving a line to another cart' => [fn (Cart $cart) => DB::table('cart_items')->where('cart_id', $cart->id)->limit(1)->update([
            'cart_id' => Cart::create(['user_id' => User::factory()->create()->id, 'business_account_id' => test()->other->id])->id,
        ])],
        'changing what a line is for' => [fn (Cart $cart) => DB::table('cart_items')->where('cart_id', $cart->id)->whereNull('product_variant_id')->update(['product_id' => test()->shirt->id])],
        'handing a cart to someone else' => [fn (Cart $cart) => DB::table('carts')->where('id', $cart->id)->update(['user_id' => User::factory()->create()->id])],
    ]);

    it('removes a cart\'s lines with the cart', function () {
        $cart = $this->carts->forUser($this->owner, $this->account);
        cartSchemaLine($cart, ['product_id' => $this->kettle->id, 'quantity' => 3]);

        $cart->delete();

        expect(CartItem::query()->count())->toBe(0);
    });
});
