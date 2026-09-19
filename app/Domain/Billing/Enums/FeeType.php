<?php

namespace App\Domain\Billing\Enums;

/**
 * A fee an administrator can price by rule (§9).
 *
 * One case today. It is an enum rather than a bare string because a fee type
 * that nothing validates is a typo away from a rule that matches nothing —
 * silently, and only visibly wrong on somebody's invoice.
 */
enum FeeType: string
{
    /**
     * Charged once, when an account is created (§5.1, §9).
     *
     * The package fee is not here: it belongs to the package and is edited on
     * the package, where what it buys is also edited. Splitting it across two
     * screens would be two places to look and two places to disagree.
     */
    case Registration = 'registration';

    /**
     * Charged on an ERP wholesale checkout for delivering the order (§14, P4-7).
     *
     * One amount per order, priced by rule like the registration fee: a rule for
     * the account's package wins over the global one, and with neither the charge
     * is zero. What a courier charges per shipment belongs to courier management
     * (§21), not here.
     */
    case WholesaleDelivery = 'wholesale_delivery';

    /**
     * Charged on an order a customer places on a partner website (§16, §17, P5-23).
     *
     * Feriwala is merchant of record and delivers the order (D12), so the charge
     * is Feriwala's, priced by rule for the website owner's package and then the
     * global rule — never a figure the storefront sends.
     */
    case WebsiteDelivery = 'website_delivery';

    /**
     * What a dedicated website costs to build, to keep a domain for, to host,
     * and to have worked on afterwards (§16.2, §24, P5-10).
     *
     * Priced by rule like every other fee, so a plan can include a website at a
     * different setup charge without a second pricing mechanism. Each is
     * charged to the account's wallet when it falls due; none of them is a
     * package feature, because a package says *whether* a website is included,
     * not what it costs.
     */
    case WebsiteSetup = 'website_setup';
    case WebsiteDomain = 'website_domain';
    case WebsiteHosting = 'website_hosting';
    case WebsiteMaintenance = 'website_maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration fee',
            self::WholesaleDelivery => 'Wholesale delivery charge',
            self::WebsiteDelivery => 'Website order delivery charge',
            self::WebsiteSetup => 'Website setup charge',
            self::WebsiteDomain => 'Domain charge',
            self::WebsiteHosting => 'Hosting charge',
            self::WebsiteMaintenance => 'Website maintenance charge',
        };
    }
}
