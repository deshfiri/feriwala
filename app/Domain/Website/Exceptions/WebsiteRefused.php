<?php

namespace App\Domain\Website\Exceptions;

use RuntimeException;

/**
 * A website step the rules do not allow, refused before anything is kept (§16).
 *
 * Each refusal says what to do about it, in the reader's language, and names
 * the field it belongs to so a form can put it where the person is looking.
 */
class WebsiteRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    /** The package does not include a dedicated website (§8.1, §16). */
    public static function notEntitled(): self
    {
        return new self(__('website.refused.not_entitled'), 'package');
    }

    /** The package's website limit is already used up (§8.1). */
    public static function limitReached(int $limit): self
    {
        return new self(__('website.refused.limit_reached', ['limit' => $limit]), 'package');
    }

    /** Somebody else's storefront already answers on this address. */
    public static function subdomainTaken(): self
    {
        return new self(__('website.refused.subdomain_taken'), 'subdomain');
    }

    public static function domainTaken(): self
    {
        return new self(__('website.refused.domain_taken'), 'domain');
    }

    /** The wallet does not hold enough to pay this charge (§24). */
    public static function insufficientBalance(): self
    {
        return new self(__('website.refused.insufficient_balance'), 'wallet');
    }

    /** The charge has already been settled, waived or cancelled. */
    public static function chargeNotOutstanding(): self
    {
        return new self(__('website.refused.charge_not_outstanding'), 'charge');
    }

    /** The website is not in a state this move is allowed from (§16.4). */
    public static function statusDoesNotAllow(): self
    {
        return new self(__('website.refused.status_does_not_allow'), 'status');
    }

    /** A closed website is finished with, and nothing reopens it. */
    public static function closed(): self
    {
        return new self(__('website.refused.closed'), 'status');
    }

    /** Only a running domain or hosting term can be renewed. */
    public static function nothingToRenew(): self
    {
        return new self(__('website.refused.nothing_to_renew'), 'service');
    }

    /** Somebody else is already working on this website; trying again works. */
    public static function busy(): self
    {
        return new self(__('website.refused.busy'), 'website');
    }

    /** Not a picture a browser renders without a plugin (§16.3, P5-12). */
    public static function imageTypeNotAccepted(): self
    {
        return new self(__('website.refused.image_type'), 'image');
    }

    public static function imageTooLarge(): self
    {
        return new self(__('website.refused.image_too_large'), 'image');
    }

    /** A closed storefront is not edited; it is finished with. */
    public static function notEditable(): self
    {
        return new self(__('website.refused.not_editable'), 'website');
    }
}
