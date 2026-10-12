<?php

namespace App\Domain\Website\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Whether a custom domain may carry a website's storefront (D30 §3).
 *
 * Separate from the registration record staff keep (D9, {@see WebsiteServiceStatus}):
 * a domain can be registered and still not be proven to point at us, and a
 * domain that serves must be both.
 *
 *   - **Unverified** — nobody has shown control of the domain yet. Where every
 *     domain starts, and where it returns when its token is reissued.
 *   - **Verified** — the domain's DNS holds the token the ERP issued for it.
 *     Not serving yet: a person activates it.
 *   - **Active** — serving: the storefront answers on it and the certificate
 *     authority check says yes.
 *   - **Suspended** — taken off the storefront by staff, without forgetting
 *     that it was verified.
 */
enum DomainRoutingStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Unverified = 'unverified';
    case Verified = 'verified';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Unverified => [self::Verified],
            self::Verified => [self::Active, self::Unverified],
            self::Active => [self::Suspended, self::Unverified],
            self::Suspended => [self::Active, self::Unverified],
        };
    }

    /**
     * None: any domain can be sent back to unverified by reissuing its token.
     */
    public function isTerminal(): bool
    {
        return false;
    }

    /**
     * Whether the domain holds its name against every other website: once it
     * has been proven, until its token is reissued.
     */
    public function holdsTheName(): bool
    {
        return $this !== self::Unverified;
    }

    protected static function statusLabelGroup(): string
    {
        return 'domain_routing';
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
