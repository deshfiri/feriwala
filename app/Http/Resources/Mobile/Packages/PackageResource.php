<?php

namespace App\Http\Resources\Mobile\Packages;

use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A package as the comparison shows it (§8.2): its own fee and the total activation cost.
 *
 * @property array{package: Package, quote: ActivationQuote, features: list<PackageFeature>} $resource
 */
class PackageResource extends JsonResource
{
    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     short_description: string|null,
     *     fee: MoneyResource,
     *     activation_total: MoneyResource,
     *     validity_days: int|null,
     *     required_deposit: MoneyResource,
     *     features: list<array{key: string, label: string, value: mixed, type: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Package $package */
        $package = $this->resource['package'];

        return [
            /** Identifies the package in `POST /packages/{package}/select`. */
            'slug' => $package->slug,
            'name' => $package->name,
            'short_description' => $package->short_description,
            'fee' => new MoneyResource($package->fee),
            /** What would actually be paid today: the registration fee plus this package, after tax. */
            'activation_total' => new MoneyResource($this->resource['quote']->total()),
            'validity_days' => $package->validity_days,
            'required_deposit' => new MoneyResource($package->required_deposit),
            /** A fixed set of rows, the same for every package. `type` is `boolean`, `limit` or `text`; a `null` limit is unlimited. */
            'features' => array_map(fn (PackageFeature $feature) => [
                'key' => $feature->value,
                'label' => $feature->label(),
                'value' => $package->feature($feature),
                'type' => $feature->type()->value,
            ], $this->resource['features']),
        ];
    }
}
