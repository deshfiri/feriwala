<?php

namespace App\Http\Resources\Mobile\Packages;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One term of the account's subscription (§8.2), as the account bought it: the name,
 * fee and features are the captured terms, not the package as it stands today.
 *
 * Built from {@see \App\Domain\Package\Queries\AccountSubscription}, whose money
 * values are already the server's own `{amount, currency, formatted}` rendering.
 *
 * @property array<string, mixed> $resource
 */
class SubscriptionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string,
     *     package: string|null,
     *     status: string,
     *     status_label: string,
     *     status_tone: string,
     *     entitles: bool,
     *     source: string,
     *     source_label: string,
     *     started_at: string|null,
     *     expires_at: string|null,
     *     grace_ends_at: string|null,
     *     cancelled_at: string|null,
     *     days_remaining: int|null,
     *     in_grace_period: bool,
     *     paid: array{amount: string, currency: string, formatted: string}|null,
     *     renewal_fee: array{amount: string, currency: string, formatted: string}|null,
     *     renewal_frequency: string|null,
     *     features?: list<array{key: string, label: string, type: string, value: mixed}>
     * }
     */
    public function toArray(Request $request): array
    {
        $term = $this->resource;

        return [
            /** Public id of the term. */
            'id' => $term['id'],
            /** The package's name as it was sold. */
            'package' => $term['package'],
            'status' => $term['status'],
            'status_label' => $term['status_label'],
            'status_tone' => $term['status_tone'],
            /** Whether this term currently grants its entitlements. */
            'entitles' => $term['entitles'],
            'source' => $term['source'],
            'source_label' => $term['source_label'],
            'started_at' => $term['started_at'],
            'expires_at' => $term['expires_at'],
            'grace_ends_at' => $term['grace_ends_at'],
            'cancelled_at' => $term['cancelled_at'],
            /** Whole days until the term ends; negative once it has. */
            'days_remaining' => $term['days_remaining'],
            'in_grace_period' => $term['in_grace_period'],
            /** What was paid for this term. */
            'paid' => $term['paid'],
            'renewal_fee' => $term['renewal_fee'],
            'renewal_frequency' => $term['renewal_frequency'],
            /** What the term grants, from its captured terms. Only on the current term. A `null` limit is unlimited. */
            $this->mergeWhen(array_key_exists('features', $term), [
                'features' => $term['features'] ?? [],
            ]),
        ];
    }
}
