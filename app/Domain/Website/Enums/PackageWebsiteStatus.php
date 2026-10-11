<?php

namespace App\Domain\Website\Enums;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAllocation;
use Carbon\CarbonImmutable;

/**
 * How a package-allocated website reads to the partner on My Websites (§16.2).
 *
 * Four answers rather than the fourteen {@see WebsiteStatus} tracks, because
 * the question on that screen is "can my shop be used", and a partner should
 * not have to learn the platform's internal lifecycle to read it. This is a
 * reading of the website and its allocation, never stored and never a way to
 * move either: statuses still change only through `transitionTo()`.
 */
enum PackageWebsiteStatus: string
{
    case PendingSetup = 'pending_setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';

    /**
     * A suspension is a decision about the website and outranks everything;
     * a lapsed term outranks setup, because a website whose domain and hosting
     * time has run out is not waiting to be set up — it is waiting to be renewed.
     */
    public static function for(?Website $website, WebsiteAllocation $allocation, ?CarbonImmutable $now = null): self
    {
        if (in_array($website?->status, [WebsiteStatus::Suspended, WebsiteStatus::TemporarilyDisabled, WebsiteStatus::Closed], true)) {
            return self::Suspended;
        }

        if ($website?->status === WebsiteStatus::PackageExpired || $allocation->hasLapsed($now)) {
            return self::Expired;
        }

        if ($website === null || $website->status->isBeingBuilt()) {
            return self::PendingSetup;
        }

        return self::Active;
    }

    public function label(): string
    {
        return __('website.allocation.statuses.'.$this->value);
    }

    /**
     * The `StatusPill` tone. The label always travels with it (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::PendingSetup => 'info',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Expired => 'danger',
        };
    }
}
