<?php

namespace App\Domain\Order\Queries;

use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The storefronts a person may take an order on for a customer: their own
 * account's, live, and ones their role is allowed to run.
 *
 * The one answer the "New order" popup and the order form both use, so the
 * button and the server never disagree about who may place an order.
 */
class CreatableStorefronts
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function for(User $user, int $accountId): array
    {
        return Website::query()
            ->where('business_account_id', $accountId)
            ->orderBy('name')
            ->get()
            ->filter(fn (Website $website) => $website->status->isLive() && Gate::forUser($user)->allows('manage', $website))
            ->map(fn (Website $website) => ['value' => $website->public_id, 'label' => $website->name])
            ->values()
            ->all();
    }

    /**
     * Where the popup sends its three requests.
     *
     * @return array{products: string, quote: string, store: string}
     */
    public function urls(): array
    {
        return [
            'products' => route('store-orders.products'),
            'quote' => route('store-orders.quote'),
            'store' => route('store-orders.store'),
        ];
    }
}
