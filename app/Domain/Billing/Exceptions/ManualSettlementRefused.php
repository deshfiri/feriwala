<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * A payment that must not be marked paid by hand.
 *
 * Every message is written to be shown to the person who tried. `overridable`
 * says whether they may still go ahead on their own say-so: true only when the
 * gateway could not confirm the money either way. A gateway that positively
 * contradicts the claim — another payment's transaction, a different amount — is
 * never something to override.
 */
class ManualSettlementRefused extends RuntimeException
{
    public function __construct(string $message, public readonly bool $overridable = false)
    {
        parent::__construct($message);
    }

    public static function notSettleable(string $status): self
    {
        return new self("This payment is {$status}, so it cannot be marked paid by hand.");
    }

    /**
     * The customer paid the Partner's own gateway (D36). Whether that money arrived
     * is between the Partner and their provider, and only Feriwala's own
     * verification with the Partner's frozen credentials can say so: a person here
     * cannot attest to a payment Feriwala did not receive.
     */
    public static function partnerOwned(): self
    {
        return new self('This payment was made to the Partner\'s own gateway, so it cannot be marked paid by hand. It is confirmed only by verifying it with that gateway.');
    }

    public static function referenceTaken(): self
    {
        return new self('That gateway transaction already belongs to another payment.');
    }

    public static function contradicted(string $why): self
    {
        return new self($why);
    }

    /**
     * The gateway did not confirm it, and staff may still decide it was paid.
     */
    public static function unverified(string $why): self
    {
        return new self(
            $why.' If you have seen the money arrive another way, tick the override box and say how.',
            overridable: true,
        );
    }
}
