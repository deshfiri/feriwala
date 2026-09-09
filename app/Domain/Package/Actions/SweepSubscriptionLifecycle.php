<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\SubscriptionPolicy;
use App\Notifications\Package\SubscriptionExpired;
use App\Notifications\Package\SubscriptionExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily pass over every term's clock (§8.4).
 *
 * Three moves, in order, because a term has to pass through them: a live term
 * approaching its end becomes **renewal due**, one past its end with grace left
 * enters the **grace period**, and one past that has **expired**. A term whose
 * end went by while nothing was sweeping — a fresh deployment, an outage — goes
 * straight from active to expired, which the status map allows precisely so
 * that gap does not need a special case.
 *
 * **Idempotent by transition, not by a marker table.** Each move is made under a
 * row lock and only from a state that permits it, so a second pass finds the
 * term already moved and does nothing. That is also what makes the message safe:
 * the notification hangs off the transition, so it is sent exactly once however
 * many times the sweep runs or however many servers run it.
 *
 * Chunked, because a daily pass over every term ever sold would load the table
 * into memory on a platform of any size (§39).
 *
 * What expiry *means* is not decided here. {@see UserPackageStatus::entitles()}
 * already stops an expired term granting anything, and
 * {@see Entitlements} is what every feature gate reads — so
 * §8.4's "package-specific features may be restricted" happens by the term
 * changing state rather than by this sweep reaching into other modules.
 */
class SweepSubscriptionLifecycle
{
    public function __construct(
        protected SubscriptionPolicy $policy,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{due: int, grace: int, expired: int}
     */
    public function handle(): array
    {
        return [
            'due' => $this->markRenewalDue(),
            'grace' => $this->enterGracePeriod(),
            'expired' => $this->expire(),
        ];
    }

    /**
     * Warn live terms that are running out.
     */
    protected function markRenewalDue(): int
    {
        $moved = 0;
        $cutoff = $this->now()->addDays($this->policy->renewalWindowDays());

        $this->terms([UserPackageStatus::Active])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff)
            ->chunkById(200, function ($terms) use (&$moved) {
                foreach ($terms as $term) {
                    if ($this->move($term, UserPackageStatus::RenewalDue)) {
                        $this->tell($term, new SubscriptionExpiring(
                            package: (string) $term->terms()?->name,
                            endsAt: $term->expires_at?->toDayDateTimeString(),
                            inGracePeriod: false,
                        ));

                        $moved++;
                    }
                }
            });

        return $moved;
    }

    /**
     * Move terms past their end into the grace period §8.4 grants them.
     */
    protected function enterGracePeriod(): int
    {
        $moved = 0;

        $this->terms([UserPackageStatus::RenewalDue])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $this->now())
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '>', $this->now())
            ->chunkById(200, function ($terms) use (&$moved) {
                foreach ($terms as $term) {
                    if ($this->move($term, UserPackageStatus::GracePeriod)) {
                        $this->tell($term, new SubscriptionExpiring(
                            package: (string) $term->terms()?->name,
                            endsAt: $term->grace_ends_at?->toDayDateTimeString(),
                            inGracePeriod: true,
                        ));

                        $moved++;
                    }
                }
            });

        return $moved;
    }

    /**
     * End terms that are past everything they were given.
     */
    protected function expire(): int
    {
        $moved = 0;

        $this->terms(UserPackageStatus::entitling())
            ->whereNotNull('expires_at')
            ->where(function (Builder $query) {
                // Grace where there is grace, expiry where there is not. A term
                // with no grace period is over the moment its term is.
                $query->where(fn (Builder $inner) => $inner
                    ->whereNotNull('grace_ends_at')
                    ->where('grace_ends_at', '<=', $this->now()))
                    ->orWhere(fn (Builder $inner) => $inner
                        ->whereNull('grace_ends_at')
                        ->where('expires_at', '<=', $this->now()));
            })
            ->chunkById(200, function ($terms) use (&$moved) {
                foreach ($terms as $term) {
                    if (! $this->move($term, UserPackageStatus::Expired)) {
                        continue;
                    }

                    $this->repointAccount($term);

                    $this->tell($term, new SubscriptionExpired(
                        package: (string) $term->terms()?->name,
                    ));

                    $moved++;
                }
            });

        return $moved;
    }

    /**
     * Make one move, under a lock, and only if it is still legal.
     *
     * Returns false when another pass got there first — which is what keeps the
     * notification beside it from being sent twice.
     */
    protected function move(UserPackage $term, UserPackageStatus $to): bool
    {
        return (bool) $this->database->transaction(function () use ($term, $to) {
            /** @var UserPackage|null $locked */
            $locked = UserPackage::query()->lockForUpdate()->find($term->id);

            if ($locked === null || ! $locked->canTransitionTo($to)) {
                return false;
            }

            $locked->transitionTo($to)->save();
            $term->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }

    /**
     * Point the account at a term that still entitles, if one is waiting.
     *
     * A renewal or a scheduled downgrade may have been paid for weeks ago and
     * be starting exactly now. Leaving the pointer on the term that just
     * expired would make the account look lapsed on every screen that reads it,
     * even though the successor is live.
     */
    protected function repointAccount(UserPackage $expired): void
    {
        $account = BusinessAccount::query()->whereKey($expired->business_account_id)->first();

        if ($account === null || $account->current_user_package_id !== $expired->id) {
            return;
        }

        $successor = $account->packages()
            ->whereKeyNot($expired->id)
            ->whereIn('status', UserPackageStatus::entitling())
            ->latest('id')
            ->first();

        if ($successor !== null) {
            $account->forceFill(['current_user_package_id' => $successor->id])->save();
        }
    }

    protected function tell(UserPackage $term, object $notification): void
    {
        BusinessAccount::query()
            ->with('owner')
            ->whereKey($term->business_account_id)
            ->first()
            ?->owner
            ?->notify($notification);
    }

    /**
     * @param  array<int, UserPackageStatus>  $statuses
     * @return Builder<UserPackage>
     */
    protected function terms(array $statuses): Builder
    {
        return UserPackage::query()->whereIn('status', $statuses);
    }

    /**
     * Now, in the platform's timezone.
     *
     * Stated rather than inherited, for the same reason the KYC sweep states
     * it: "expires on the 30th" has to mean the 30th where the account holder
     * is, and a server rebuilt in another region must not move every term.
     */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }
}
