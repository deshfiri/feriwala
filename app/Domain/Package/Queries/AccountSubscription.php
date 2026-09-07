<?php

namespace App\Domain\Package\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;

/**
 * What an account's own subscription looks like to them (§8.2, §8.4).
 *
 * Built from the **captured terms**, not the package as it stands today. The
 * whole point of the snapshot is that an account sees what it bought; reading
 * the live row here would undo that at the last step, on the one screen where
 * the difference is visible to the person it affects.
 *
 * Shows history as well as the current term. "What am I on" and "what have I
 * paid for" are both questions an account holder asks, and a screen answering
 * only the first sends them to support for the second.
 */
class AccountSubscription
{
    /**
     * The subscription an account is currently on, or null before it has one.
     *
     * @return array<string, mixed>|null
     */
    public function current(BusinessAccount $account): ?array
    {
        // A term that grants something, or one waiting to be paid for — the
        // two an account holder would call "mine".
        $live = [
            UserPackageStatus::Active,
            UserPackageStatus::RenewalDue,
            UserPackageStatus::GracePeriod,
            UserPackageStatus::PendingPayment,
        ];

        /*
         * `current_user_package_id` is a pointer, and cancelling or expiring a
         * term does not clear it — so the pointer alone would keep presenting a
         * closed term as the current one, complete with its renewal fee and the
         * entitlements it no longer grants. Its status has to agree.
         */
        $pointed = $account->currentPackage()->with('package')->first();

        $subscription = $pointed !== null && in_array($pointed->status, $live, true)
            ? $pointed
            : $account->packages()->whereIn('status', $live)->latest('id')->first();

        return $subscription === null ? null : $this->present($subscription, withFeatures: true);
    }

    /**
     * Every term this account has held, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(BusinessAccount $account): array
    {
        return $account->packages()
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserPackage $subscription) => $this->present($subscription))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(UserPackage $subscription, bool $withFeatures = false): array
    {
        $terms = $subscription->terms();

        $row = [
            'id' => $subscription->public_id,

            // The name as it was sold. A package renamed since must not
            // retitle a term somebody already holds.
            'package' => $terms?->name,

            'status' => $subscription->status->value,
            'status_label' => $subscription->status->label(),
            'status_tone' => $subscription->status->tone(),
            'entitles' => $subscription->status->entitles(),

            'source' => $subscription->source->value,
            'source_label' => $subscription->source->label(),

            'started_at' => $subscription->started_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),

            'days_remaining' => $this->daysRemaining($subscription),
            'in_grace_period' => $subscription->isInGracePeriod(),

            'paid' => $subscription->paid_fee_minor?->jsonSerialize(),

            'renewal_fee' => $terms?->renewalFeeMinor === null ? null : [
                'minor_units' => $terms->renewalFeeMinor,
                'currency' => $terms->currencyCode,
            ],
            'renewal_frequency' => $terms?->renewalFrequency,
        ];

        if (! $withFeatures || $terms === null) {
            return $row;
        }

        // What this term actually grants, resolved from the snapshot — so an
        // account reading its limits sees the ones it is held to.
        $row['features'] = array_map(
            fn (PackageFeature $feature) => [
                'key' => $feature->value,
                'label' => $feature->label(),
                'type' => $feature->type()->value,
                'value' => $terms->feature($feature),
            ],
            PackageFeature::cases(),
        );

        return $row;
    }

    /**
     * Whole days until the term ends, negative once it has.
     */
    protected function daysRemaining(UserPackage $subscription): ?int
    {
        if ($subscription->expires_at === null) {
            return null;
        }

        // Floor, so "1 day left" never appears for something due in an hour.
        return (int) floor(
            now()->diffInHours($subscription->expires_at, absolute: false) / 24
        );
    }
}
