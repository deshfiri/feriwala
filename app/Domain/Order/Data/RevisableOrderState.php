<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The order columns only an applied revision may change, in the one shape the
 * database compares them in (D38).
 *
 * `orders_revisable_columns_follow_revision` accepts a change only when the
 * applied revision's `before_snapshot.order` and `final_snapshot.order` match
 * the old and new row column by column, compared as text. So both snapshots
 * are built here and nowhere else: a money column as its two-decimal string
 * (`numeric(19,2)` reads back exactly so), an address as the object it is, an
 * enum as its value, an id as an integer, an empty column as null.
 */
final class RevisableOrderState
{
    /** Kept in step with the migration that created the trigger (2026_11_24_162410). */
    public const COLUMNS = [
        'customer', 'billing_address', 'shipping_address',
        'subtotal', 'discount', 'delivery',
        'delivery_area', 'delivery_district_id', 'delivery_fee_rule_id', 'delivery_tax', 'delivery_tax_mode',
        'tax', 'tax_included', 'cod_fee', 'total', 'coupon_code',
    ];

    private const JSON_COLUMNS = ['customer', 'billing_address', 'shipping_address'];

    private const INTEGER_COLUMNS = ['delivery_district_id', 'delivery_fee_rule_id'];

    /**
     * The order's revisable columns exactly as the database holds them now.
     *
     * Read from the row, not the model, so nothing a cast or an unsaved
     * attribute does can make the snapshot disagree with the trigger.
     *
     * @return array<string, mixed>
     */
    public static function current(Order $order): array
    {
        $row = (array) DB::table('orders')->where('id', $order->id)->first(self::COLUMNS);

        if ($row === []) {
            throw new InvalidArgumentException("Order {$order->reference} does not exist.");
        }

        $state = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;

            $state[$column] = match (true) {
                $value === null => null,
                in_array($column, self::JSON_COLUMNS, true) => json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR),
                in_array($column, self::INTEGER_COLUMNS, true) => (int) $value,
                default => (string) $value,
            };
        }

        return $state;
    }

    /**
     * The state an order will have once `$changes` are applied to `$current`.
     *
     * Every key must be a revisable column; anything else — an identity column
     * above all — is refused here as the database would refuse it.
     *
     * @param  array<string, mixed>  $current  a {@see current()} reading
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function with(array $current, array $changes): array
    {
        foreach (array_keys($changes) as $column) {
            if (! in_array($column, self::COLUMNS, true)) {
                throw new InvalidArgumentException("[{$column}] is not a column a revision may change (D38).");
            }
        }

        $next = $current;

        foreach ($changes as $column => $value) {
            $next[$column] = self::normalise($column, $value);
        }

        return $next;
    }

    /**
     * The columns whose value differs between two readings.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changedColumns(array $before, array $after): array
    {
        return array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => self::comparable($before[$column] ?? null) !== self::comparable($after[$column] ?? null),
        ));
    }

    /**
     * The attributes to write to the order for a reading — what the model's
     * casts expect.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function attributes(array $state, string $currency): array
    {
        $attributes = [];

        foreach (self::COLUMNS as $column) {
            $value = $state[$column] ?? null;

            $attributes[$column] = match (true) {
                $value === null => null,
                in_array($column, ['subtotal', 'discount', 'delivery', 'delivery_tax', 'tax', 'tax_included', 'cod_fee', 'total'], true) => Money::fromDecimal((string) $value, Currency::from($currency)),
                default => $value,
            };
        }

        return $attributes;
    }

    private static function normalise(string $column, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->toDecimal(),
            $value instanceof BackedEnum => $value->value,
            in_array($column, self::INTEGER_COLUMNS, true) => (int) $value,
            in_array($column, self::JSON_COLUMNS, true) => is_array($value)
                ? $value
                : throw new InvalidArgumentException("[{$column}] must be an array."),
            default => (string) $value,
        };
    }

    /**
     * A value reduced to what the database compares: canonical JSON for an
     * address (key order does not matter in jsonb), text for everything else.
     */
    private static function comparable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
