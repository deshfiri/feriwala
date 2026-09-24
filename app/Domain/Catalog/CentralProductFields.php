<?php

namespace App\Domain\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * The central product fields §12 puts beyond a request's reach unless that
 * request is the one that owns them.
 *
 * §12 names what a regular user must not modify — the central SKU, central
 * stock, the central wholesale price and locked product information — and a
 * business account never reaches a catalogue write at all (P3-15). This class
 * is the second half: among the requests an authorised editor *can* send, each
 * protected field is accepted by exactly one endpoint, and every other endpoint
 * rejects it outright rather than silently ignoring it. A crafted request that
 * slips `status` into a product edit, or `wholesale_price` into a bulk
 * action, gets a validation error naming the field — not a quiet no-op that
 * looks like it might have worked.
 *
 * The rule is `missing`, not `prohibited`: `prohibited` lets an empty value
 * through, so `status: null` would pass and could reach the validated data. A
 * protected key is refused whenever it is present at all.
 *
 * One list, so the form requests and the inline controller validation cannot
 * disagree about what is protected.
 */
final class CentralProductFields
{
    /**
     * The central SKU and barcode (§12 "Modify Central SKU").
     *
     * @var array<int, string>
     */
    public const IDENTIFIERS = ['sku', 'barcode'];

    /**
     * Central wholesale pricing (§12 "Modify Central wholesale price"): the
     * figures, and the quantity bands built on them.
     *
     * @var array<int, string>
     */
    public const PRICING = ['wholesale_price', 'base_cost', 'tiers'];

    /**
     * Locked product information (§12).
     *
     * The lifecycle, channels, featuring and eligibility each have their own
     * endpoint with its own permission (§11.2); a general edit that could set
     * them would be a way round that permission. The rest — the currency, the
     * identifiers the system assigns, a variation's fixed combination — no
     * request may set at all.
     *
     * @var array<int, string>
     */
    public const LOCKED = [
        'status', 'dropshipping_status', 'wholesale_status',
        'is_featured', 'featured_at', 'published_at',
        'package_scope', 'account_scope',
        'currency_code', 'public_id', 'id', 'product_id', 'combination_key',
    ];

    /**
     * Central stock (§12 "Modify Central stock").
     *
     * Stock is not a product column and is never written by a catalogue
     * request — it belongs to inventory (§19). Matched by pattern rather than a
     * guessed list of names, so `stock`, `available_stock` or `stock_quantity`
     * are all refused without this class having to predict what someone types.
     * `out_of_stock` is a status *value*, never an input key, so it is unaffected.
     */
    public const STOCK_KEY_PATTERN = '/stock/i';

    /**
     * A list of products in one request (§12 "Import external Products").
     *
     * Only a bulk action, which acts on products that already exist, carries
     * one. Products enter the catalogue one at a time, through the form that
     * asks for everything a product needs — never as a batch posted anywhere.
     *
     * @var array<int, string>
     */
    public const BATCH = ['products'];

    /**
     * The rules for one endpoint: every protected field it does not own, and
     * every stock key, list of products and uploaded file the request carries
     * without owning it, refused when present.
     *
     * An uploaded file is refused under any name the endpoint does not own, so a
     * spreadsheet posted to the product form is an error rather than an attachment
     * nobody reads (§12 "Upload an unauthorized Product").
     *
     * @param  array<int, string>  $owned  the protected fields, lists and file inputs this endpoint legitimately accepts
     * @param  array<array-key, mixed>  $input  the request's input, files included
     * @return array<string, array<int, string>>
     */
    public static function rules(array $owned, array $input): array
    {
        $rules = [];

        foreach ([...self::protected(), ...self::BATCH] as $field) {
            if (! in_array($field, $owned, true)) {
                $rules[$field] = ['missing'];
            }
        }

        foreach ([...self::stockKeys($input), ...self::fileKeys($input)] as $key) {
            if (! in_array($key, $owned, true)) {
                $rules[self::ruleKey($key)] = ['missing'];
            }
        }

        return $rules;
    }

    /**
     * A message naming why each refused field was refused.
     *
     * @param  array<array-key, mixed>  $input  the request's input
     * @return array<string, string>
     */
    public static function messages(array $input): array
    {
        $messages = [];

        foreach (self::protected() as $field) {
            $messages["{$field}.missing"] = __('catalog.restrictions.not_here');
        }

        foreach (self::BATCH as $field) {
            $messages["{$field}.missing"] = __('catalog.restrictions.batch');
        }

        foreach (self::stockKeys($input) as $key) {
            $messages[self::ruleKey($key).'.missing'] = __('catalog.restrictions.stock');
        }

        foreach (self::fileKeys($input) as $key) {
            $messages[self::ruleKey($key).'.missing'] = __('catalog.restrictions.file');
        }

        return $messages;
    }

    /**
     * The top-level input keys that carry an uploaded file, alone or in a list.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<int, string>
     */
    public static function fileKeys(array $input): array
    {
        return array_values(array_filter(
            array_keys($input),
            fn (int|string $key) => is_string($key)
                && collect(Arr::flatten([$input[$key]]))->contains(fn (mixed $item) => $item instanceof UploadedFile),
        ));
    }

    /**
     * The top-level input keys that name stock.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<int, string>
     */
    public static function stockKeys(array $input): array
    {
        return array_values(array_filter(
            array_keys($input),
            fn (int|string $key) => is_string($key) && preg_match(self::STOCK_KEY_PATTERN, $key) === 1,
        ));
    }

    /**
     * @return array<int, string>
     */
    protected static function protected(): array
    {
        return [...self::IDENTIFIERS, ...self::PRICING, ...self::LOCKED];
    }

    /**
     * A top-level key as a rule key. A dot in a rule key means nesting, so a
     * key named `stock.level` would otherwise be looked for one level down and
     * never found.
     */
    protected static function ruleKey(string $key): string
    {
        return str_replace('.', '\.', $key);
    }
}
