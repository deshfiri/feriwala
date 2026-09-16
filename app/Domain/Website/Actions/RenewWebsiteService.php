<?php

namespace App\Domain\Website\Actions;

use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Package\Entitlements;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Currency;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Buy another term of a domain or of hosting (§16.2, §41, P5-11).
 *
 * Raises the charge at **today's** price, settles it from the wallet, and only
 * then extends the term — so a renewal that cannot be paid for extends nothing,
 * and a term is never extended twice by a retried request: the charge is
 * created once per period and its settlement is idempotent.
 *
 * The new term runs from whichever is later, the current expiry or today.
 * Renewing early should not throw away the days already paid for, and renewing
 * something that lapsed last month should not backdate a term into the past.
 */
class RenewWebsiteService
{
    public function __construct(
        protected FeeRuleResolver $fees,
        protected Entitlements $entitlements,
        protected SettleWebsiteCharge $settle,
        protected MoveWebsiteStatus $move,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     * @throws LockTimeout
     */
    public function renewDomain(WebsiteDomain $domain, User $actor, int $termMonths = 12): WebsiteDomain
    {
        $this->assertRenewable($domain->status);

        $website = $domain->website;
        $charge = $this->raise($website, WebsiteChargeType::Domain, $domain->expires_at, $termMonths, $actor, [
            'website_domain_id' => $domain->id,
        ]);

        $this->settle->handle($charge, $actor);

        $domain->forceFill([
            'status' => WebsiteServiceStatus::Active,
            'expires_at' => $this->termEnd($domain->expires_at, $termMonths),
            'reminded_at' => null,
            'reminder_stage' => null,
        ])->save();

        $this->backToNormal($website, WebsiteStatus::DomainRenewalPending, $actor);

        return $domain;
    }

    /**
     * @throws WebsiteRefused
     * @throws LockTimeout
     */
    public function renewHosting(WebsiteHosting $hosting, User $actor, int $termMonths = 12): WebsiteHosting
    {
        $this->assertRenewable($hosting->status);

        $website = $hosting->website;
        $charge = $this->raise($website, WebsiteChargeType::Hosting, $hosting->expires_at, $termMonths, $actor, [
            'website_hosting_id' => $hosting->id,
        ]);

        $this->settle->handle($charge, $actor);

        $hosting->forceFill([
            'status' => WebsiteServiceStatus::Active,
            'expires_at' => $this->termEnd($hosting->expires_at, $termMonths),
            'reminded_at' => null,
            'reminder_stage' => null,
        ])->save();

        $this->backToNormal($website, WebsiteStatus::HostingRenewalPending, $actor);

        return $hosting;
    }

    /**
     * The charge for one more term, raised once.
     *
     * A renewal that was started, charged and then abandoned leaves a due
     * charge for that exact period; asking again finds it rather than raising a
     * second one for the same year.
     *
     * @param  array<string, int>  $links
     */
    protected function raise(
        Website $website,
        WebsiteChargeType $type,
        ?CarbonImmutable $currentExpiry,
        int $termMonths,
        User $actor,
        array $links,
    ): WebsiteCharge {
        $start = $this->termStart($currentExpiry);
        $end = $start->addMonths($termMonths);

        /** @var WebsiteCharge|null $existing */
        $existing = WebsiteCharge::query()
            ->where('website_id', $website->id)
            ->where('type', $type->value)
            ->where('status', WebsiteChargeStatus::Due->value)
            ->where('period_start', $start)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $subscription = $this->entitlements->activePackage($website->businessAccount);
        $currency = Currency::from($website->currency_code);

        return WebsiteCharge::create([
            ...$links,
            'website_id' => $website->id,
            'business_account_id' => $website->business_account_id,
            'type' => $type,
            'status' => WebsiteChargeStatus::Due,
            'currency_code' => $currency->value,
            'amount_minor' => $this->fees->websiteCharge($type->feeType(), $subscription?->package, $currency),
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => CarbonImmutable::now(),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Nothing is waiting on a renewal any more, so the shop is simply live.
     *
     * Only from the renewal state this renewal answers: a website suspended for
     * something else is not restored by paying for hosting.
     */
    protected function backToNormal(Website $website, WebsiteStatus $from, User $actor): void
    {
        if ($website->status !== $from) {
            return;
        }

        $this->move->handle(
            $website,
            WebsiteStatus::Active,
            WebsiteStatusChangeSource::Billing,
            new StatusChange(
                actorId: $actor->id,
                reason: 'renewed',
                publicNote: __('website.notes.renewed'),
            ),
        );
    }

    protected function termStart(?CarbonImmutable $currentExpiry): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return $currentExpiry !== null && $currentExpiry->isFuture() ? $currentExpiry : $now;
    }

    protected function termEnd(?CarbonImmutable $currentExpiry, int $termMonths): CarbonImmutable
    {
        return $this->termStart($currentExpiry)->addMonths($termMonths);
    }

    /**
     * @throws WebsiteRefused
     */
    protected function assertRenewable(WebsiteServiceStatus $status): void
    {
        if ($status === WebsiteServiceStatus::Cancelled) {
            throw WebsiteRefused::nothingToRenew();
        }
    }
}
