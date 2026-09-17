<?php

namespace App\Domain\Website\Exceptions;

use App\Domain\Website\Data\WebsitePricingTerms;
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

    /** The account may not sell this product on this channel (§12, §15). */
    public static function productNotEligible(): self
    {
        return new self(__('website.refused.product_not_eligible'), 'product');
    }

    /** Choosing the same product twice is the same choice. */
    public static function alreadySelected(): self
    {
        return new self(__('website.refused.already_selected'), 'product');
    }

    /** Nothing goes on sale at a price nobody set (§15.1). */
    public static function priceRequired(): self
    {
        return new self(__('website.refused.price_required'), 'price');
    }

    /** The package's published-product limit is used up (§8.1). */
    public static function publishLimitReached(int $limit): self
    {
        return new self(__('website.refused.publish_limit', ['limit' => $limit]), 'product');
    }

    /**
     * The price is outside what the administrator allows (§15.1).
     *
     * The reason names which bound was crossed, and the message carries the
     * bound itself: "too low" without the floor is not something a partner can
     * act on.
     */
    public static function priceRefused(string $reason, WebsitePricingTerms $terms): self
    {
        return new self(__('website.refused.price.'.$reason, [
            'minimum' => $terms->minimum?->toDecimal() ?? '—',
            'maximum' => $terms->maximum?->toDecimal() ?? '—',
            'ceiling' => $terms->marginCeiling()?->toDecimal() ?? '—',
        ]), 'price');
    }

    /** A promotion that is not a reduction is a price rise wearing a badge. */
    public static function promotionNotAReduction(): self
    {
        return new self(__('website.refused.promotion_not_a_reduction'), 'promotional_price');
    }

    /** The administrator holds this setting for this product (§15.1). */
    public static function fieldLocked(string $field): self
    {
        return new self(__('website.refused.field_locked', [
            'field' => __('website.fields.'.$field),
        ]), $field);
    }

    /** A category belongs to one storefront, and this is not one of its own. */
    public static function categoryNotFound(): self
    {
        return new self(__('website.refused.category_not_found'), 'website_category_id');
    }

    /** A webhook must go to a public HTTPS address (contract §3.3, §7.2). */
    public static function webhookAddressRefused(): self
    {
        return new self(__('website.refused.webhook_address'), 'url');
    }

    /** A delivery that is not in the failed queue has nothing to retry. */
    public static function nothingToRetry(): self
    {
        return new self(__('website.refused.nothing_to_retry'), 'delivery');
    }

    /** A revoked credential is finished with; issue a new one. */
    public static function credentialRevoked(): self
    {
        return new self(__('website.refused.credential_revoked'), 'credential');
    }

    /** Two categories on one storefront cannot share an address. */
    public static function categoryNameTaken(): self
    {
        return new self(__('website.refused.category_name_taken'), 'name');
    }
}
