<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Models\User;

/**
 * A Partner choosing whether their own storefront's cash-on-delivery customers
 * confirm with an SMS code.
 *
 * The choice is that storefront's alone — a Partner with several storefronts sets
 * each on its own. While the platform requires the code of every storefront it
 * cannot be turned off, and that is refused here rather than left to the screen.
 * Switching it **on** is always allowed.
 */
class SetWebsiteSmsVerification
{
    public function __construct(
        protected CheckoutVerification $verification,
        protected RecordAuditLog $audit,
        protected AnnounceWebsiteUpdated $announce,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(Website $website, User $actor, bool $enabled): Website
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        if (! $enabled && $this->verification->lockedFor($website)) {
            throw WebsiteRefused::smsVerificationRequiredByPlatform();
        }

        $before = $website->sms_verification_enabled;

        if ($before === $enabled) {
            return $website;
        }

        $website->forceFill(['sms_verification_enabled' => $enabled])->save();

        $this->audit->handle(new AuditEntry(
            action: 'website.sms_verification_changed',
            actorId: $actor->id,
            auditableType: Website::class,
            auditableId: $website->id,
            before: ['enabled' => $before],
            after: ['enabled' => $enabled],
            accountId: $website->business_account_id,
            module: 'website',
        ));

        $this->announce->handle($website);

        return $website;
    }
}
