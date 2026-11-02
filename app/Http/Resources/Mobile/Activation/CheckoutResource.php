<?php

namespace App\Http\Resources\Mobile\Activation;

use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Package\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The activation checkout: the package, the full breakdown, the coupon's outcome,
 * how long the attempt stays open and the gateways to pay with (§9).
 *
 * @property array{package: Package, quote: ActivationQuote, coupon: CouponOutcome|null, deadline: array{hours: int|null, expires_at: string|null, expired: bool, reconciling: bool}, gateways: list<array{name: string, label: string, fx: array<string, mixed>|null}>} $resource
 */
class CheckoutResource extends JsonResource
{
    /**
     * @return array{
     *     package: array{slug: string, name: string, validity_days: int|null},
     *     quote: QuoteResource,
     *     coupon: array{accepted: bool, code: string|null, name: string|null, discount: array{amount: string, currency: string, formatted: string}|null, reason: string|null}|null,
     *     deadline: array{hours: int|null, expires_at: string|null, expired: bool, reconciling: bool},
     *     gateways: list<array{name: string, label: string, fx: array<string, mixed>|null}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Package $package */
        $package = $this->resource['package'];

        return [
            'package' => [
                'slug' => $package->slug,
                'name' => $package->name,
                'validity_days' => $package->validity_days,
            ],
            'quote' => new QuoteResource($this->resource['quote']),
            /** The held coupon code's outcome, with the reason when it is refused. Null when no code was sent. */
            'coupon' => $this->resource['coupon']?->toArray(),
            /**
             * Where the last attempt got to. `expires_at` while one is open, `expired` once the
             * last one lapsed, and `reconciling` when money arrived after it closed and staff
             * are sorting it out — in which case paying again is refused.
             */
            'deadline' => $this->resource['deadline'],
            /** Gateways that can take Taka. `name` is what `POST /checkout` expects; `fx` explains any currency conversion. */
            'gateways' => $this->resource['gateways'],
        ];
    }
}
