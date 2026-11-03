<?php

namespace App\Domain\Billing\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * How far a provider can be trusted to carry a Partner-owned payment (D36).
 *
 * A provider is only as ready as its proof. Until the adapter contract tests for a
 * provider pass — its own credentials used to start a payment, the frozen version
 * to verify it, the exact amount and currency checked, the reference's owner
 * checked, a repeated callback made harmless, a rotated credential leaving old
 * payments alone, no Feriwala credential ever used by accident, and the provider's
 * own rules for proving a notification is genuine enforced — it is not presented
 * as production-ready, whatever its driver can do.
 *
 * The one place the answer is written down, so the Admin and Partner screens, the
 * eligibility check and the release checklist cannot disagree. Moving a provider
 * up is a deliberate edit here, made in the same commit as the tests that earn it.
 */
enum PartnerGatewayProviderSupport: string
{
    use HasTranslatedLabel;

    /** Its contract tests pass: the only kind that may be called production-ready. */
    case SupportedAndTested = 'supported_tested';

    /** The driver exists and can verify a payment, but no contract test proves it for Partner use yet. */
    case ImplementedUnverified = 'implemented_unverified';

    /** Cannot carry a Partner-owned payment at all: it offers no server-side verification. */
    case Unsupported = 'unsupported';

    /**
     * What each provider is classed as today. Anything not named here — a driver
     * added later, a name nobody has heard of — is unsupported until someone decides.
     */
    public static function for(string $provider): self
    {
        return match (strtolower($provider)) {
            'sslcommerz' => self::SupportedAndTested,
            'surjopay', 'amarpay', 'bkash', 'stripe', 'paypal', 'eps' => self::ImplementedUnverified,
            default => self::Unsupported,
        };
    }

    public function isProductionReady(): bool
    {
        return $this === self::SupportedAndTested;
    }

    /**
     * Whether a Partner may be given it at all, with the warning the screens owe for an unverified one.
     */
    public function canCarryPartnerPayments(): bool
    {
        return $this !== self::Unsupported;
    }

    public function tone(): string
    {
        return match ($this) {
            self::SupportedAndTested => 'success',
            self::ImplementedUnverified => 'warning',
            self::Unsupported => 'danger',
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'partner_gateway_support';
    }
}
