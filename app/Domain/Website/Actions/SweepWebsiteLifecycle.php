<?php

namespace App\Domain\Website\Actions;

use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Wallet\DepositGuard;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Enums\WebsiteStatusReason;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Notifications\Website\WebsiteRenewalDue;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The daily pass over every website's clock (§16.4, §24.3, §41, P5-11, P5-15).
 *
 * Nothing in §16.4 happens because somebody clicked something. A package lapses,
 * a balance falls below what was agreed, a domain reaches its last month — all
 * of them are dates passing while nobody is looking, so something has to come
 * round and ask.
 *
 * Four questions per website, in this order, because each depends on the last:
 *
 *   1. **Is the package still entitling one?** If not the website enters its
 *      grace period, and when that runs out, package expired. §24.3 is explicit
 *      that a lapse is a grace period rather than a severed shop.
 *   2. **Does the wallet still hold what was agreed?** A shortfall flags the
 *      website; meeting it again clears the flag.
 *   3. **Has a domain or hosting term run out?** An expired address is a shop
 *      nobody can reach, so the website is disabled rather than left claiming to
 *      be live.
 *   4. **Is one falling due?** The website says so, and the partner is told —
 *      once per reminder stage, not once per day for a month.
 *
 * **Idempotent by construction.** Every move goes through
 * {@see MoveWebsiteStatus}, which refuses a move the map does not allow and
 * does nothing when the website is already there — so a second pass, or two
 * servers running it at once, finds the work done. The reminder stage stored on
 * each service is what stops a message being sent twice.
 *
 * A closed website is not swept: it is finished with.
 */
class SweepWebsiteLifecycle
{
    public function __construct(
        protected Entitlements $entitlements,
        protected DepositGuard $guard,
        protected MoveWebsiteStatus $move,
    ) {}

    /**
     * @return array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $counts = [
            'grace' => 0, 'expired' => 0, 'restored' => 0,
            'low_balance' => 0, 'renewals' => 0, 'disabled' => 0, 'reminders' => 0,
        ];

        Website::query()
            ->open()
            ->with(['businessAccount'])

            // Chunked: a daily pass over every website ever opened would load
            // the table into memory on a platform of any size (§39).
            ->chunkById(100, function (Collection $websites) use (&$counts, $now) {
                foreach ($websites as $website) {
                    $this->sweep($website, $counts, $now);
                }
            });

        return $counts;
    }

    /**
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function sweep(Website $website, array &$counts, CarbonImmutable $now): void
    {
        $account = $website->businessAccount;
        $entitled = $account->canTransact()
            && $this->entitlements->allows($account, PackageFeature::DedicatedWebsite);

        if (! $entitled) {
            $this->withdraw($website, $counts, $now);

            return;
        }

        $this->restore($website, $counts);
        $this->checkTheWallet($website, $counts);
        $this->checkTheTerms($website, $counts, $now);
    }

    /**
     * The package no longer includes a website: grace first, then expiry (§24.3).
     *
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function withdraw(Website $website, array &$counts, CarbonImmutable $now): void
    {
        if ($website->status->isLive() && $website->status !== WebsiteStatus::GracePeriod) {
            $this->move->handle(
                $website,
                WebsiteStatus::GracePeriod,
                WebsiteStatusChangeSource::Scheduler,
                new StatusChange(
                    reason: WebsiteStatusReason::PackageLapsed->value,
                    publicNote: WebsiteStatusReason::PackageLapsed->note(),
                ),
                ['grace_ends_at' => $now->addDays((int) config('website.grace_days', 7))],
            );

            $counts['grace']++;

            return;
        }

        if ($website->status === WebsiteStatus::GracePeriod
            && $website->grace_ends_at !== null
            && $website->grace_ends_at->isBefore($now)) {
            $this->move->handle(
                $website,
                WebsiteStatus::PackageExpired,
                WebsiteStatusChangeSource::Scheduler,
                new StatusChange(
                    reason: WebsiteStatusReason::GraceEnded->value,
                    publicNote: WebsiteStatusReason::GraceEnded->note(),
                ),
            );

            $counts['expired']++;
        }
    }

    /**
     * The package is entitling again, so a website held back for it comes back.
     *
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function restore(Website $website, array &$counts): void
    {
        if (! in_array($website->status, [WebsiteStatus::GracePeriod, WebsiteStatus::PackageExpired], true)) {
            return;
        }

        $this->move->handle(
            $website,
            WebsiteStatus::Active,
            WebsiteStatusChangeSource::Scheduler,
            new StatusChange(reason: WebsiteStatusReason::PackageRestored->value, publicNote: WebsiteStatusReason::PackageRestored->note()),
            ['grace_ends_at' => null],
        );

        $counts['restored']++;
    }

    /**
     * What the account agreed to hold, checked against what it holds (§24.3).
     *
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function checkTheWallet(Website $website, array &$counts): void
    {
        $wallet = $this->guard->walletFor($website->businessAccount);

        if ($wallet === null) {
            return;
        }

        $meets = $this->guard->permits($wallet);

        if (! $meets && $website->status === WebsiteStatus::Active) {
            $this->move->handle(
                $website,
                WebsiteStatus::LowWalletBalance,
                WebsiteStatusChangeSource::Scheduler,
                new StatusChange(reason: WebsiteStatusReason::BalanceBelowMinimum->value, publicNote: WebsiteStatusReason::BalanceBelowMinimum->note()),
            );

            $counts['low_balance']++;

            return;
        }

        if ($meets && $website->status === WebsiteStatus::LowWalletBalance) {
            $this->move->handle(
                $website,
                WebsiteStatus::Active,
                WebsiteStatusChangeSource::Scheduler,
                new StatusChange(reason: WebsiteStatusReason::BalanceRestored->value, publicNote: WebsiteStatusReason::BalanceRestored->note()),
            );

            $counts['restored']++;
        }
    }

    /**
     * Domains and hosting terms: expired, or falling due (§41, P5-11).
     *
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function checkTheTerms(Website $website, array &$counts, CarbonImmutable $now): void
    {
        /** @var Collection<int, WebsiteDomain> $domains */
        $domains = $website->domains()->where('status', WebsiteServiceStatus::Active->value)->get();

        /** @var Collection<int, WebsiteHosting> $hostings */
        $hostings = $website->hostings()->where('status', WebsiteServiceStatus::Active->value)->get();

        foreach ($domains as $domain) {
            $this->checkOneTerm(
                $website, $domain, 'domain', WebsiteStatus::DomainRenewalPending, $counts, $now,
            );
        }

        foreach ($hostings as $hosting) {
            $this->checkOneTerm(
                $website, $hosting, 'hosting', WebsiteStatus::HostingRenewalPending, $counts, $now,
            );
        }
    }

    /**
     * @param  array{grace: int, expired: int, restored: int, low_balance: int, renewals: int, disabled: int, reminders: int}  $counts
     */
    protected function checkOneTerm(
        Website $website,
        WebsiteDomain|WebsiteHosting $service,
        string $kind,
        WebsiteStatus $pending,
        array &$counts,
        CarbonImmutable $now,
    ): void {
        $days = $service->daysRemaining($now);

        if ($days === null) {
            return;
        }

        if ($days < 0) {
            $service->forceFill(['status' => WebsiteServiceStatus::Expired])->save();

            if ($website->status->isLive()) {
                $this->move->handle(
                    $website,
                    WebsiteStatus::TemporarilyDisabled,
                    WebsiteStatusChangeSource::Scheduler,
                    new StatusChange(
                        reason: WebsiteStatusReason::from($kind.'_expired')->value,
                        publicNote: WebsiteStatusReason::from($kind.'_expired')->note(),
                    ),
                );

                $counts['disabled']++;
            }

            return;
        }

        $stage = $this->reminderStage($days);

        if ($stage === null) {
            return;
        }

        if ($website->status === WebsiteStatus::Active) {
            $this->move->handle(
                $website,
                $pending,
                WebsiteStatusChangeSource::Scheduler,
                new StatusChange(
                    reason: WebsiteStatusReason::from($kind.'_renewal_due')->value,
                    publicNote: WebsiteStatusReason::from($kind.'_renewal_due')->note(),
                ),
            );

            $counts['renewals']++;
        }

        // Once per stage. The sweep runs daily; a partner should hear about a
        // renewal four times, not thirty.
        if ($service->reminder_stage !== null && $service->reminder_stage <= $stage) {
            return;
        }

        $service->forceFill(['reminder_stage' => $stage, 'reminded_at' => $now])->save();

        $website->businessAccount->owner?->notify(new WebsiteRenewalDue(
            website: $website->name,
            websiteId: $website->public_id,
            service: $kind,
            expiresAt: $service->expires_at?->toDateString(),
            daysRemaining: $days,
        ));

        $counts['reminders']++;
    }

    /**
     * Which warning this many days out belongs to, if any.
     *
     * The smallest configured threshold the remaining days still fit inside, so
     * a website swept for the first time a week before expiry gets the seven-day
     * warning rather than the thirty-day one it has already missed.
     */
    protected function reminderStage(int $days): ?int
    {
        /** @var array<int, int> $stages */
        $stages = config('website.renewal_reminder_days', [30, 14, 7, 1]);

        $applicable = array_values(array_filter($stages, fn (int $stage) => $days <= $stage));

        return $applicable === [] ? null : min($applicable);
    }
}
