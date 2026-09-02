<?php

namespace App\Domain\Package\Enums;

/**
 * The entitlements a package can grant (§8.1).
 *
 * A closed list rather than free-form key/value rows. Feature checks are
 * scattered across the whole ERP — "may this account publish another product",
 * "may it add another staff member" — and a typo in a string key would fail open
 * or closed silently. An enum makes the mistake a fatal one at the call site.
 *
 * Limits use null to mean **unlimited**, never 0 or -1. Zero is a real answer
 * (a package that grants no staff at all), so overloading it would make
 * "no staff allowed" and "unlimited staff" indistinguishable.
 */
enum PackageFeature: string
{
    // Dedicated website (§16)
    case DedicatedWebsite = 'dedicated_website';
    case DomainIncluded = 'domain_included';
    case HostingIncluded = 'hosting_included';

    // Limits (§8.1)
    case ProductPublishLimit = 'product_publish_limit';
    case OrderLimit = 'order_limit';
    case StaffLimit = 'staff_limit';
    case WebsiteLimit = 'website_limit';
    case SmsQuota = 'sms_quota';

    // Facilities
    case ApiAccess = 'api_access';
    case FulfillmentEnabled = 'fulfillment_enabled';
    case CourierEnabled = 'courier_enabled';
    case WholesaleEnabled = 'wholesale_enabled';
    case DropshippingEnabled = 'dropshipping_enabled';

    // Service levels
    case SupportLevel = 'support_level';
    case ReportAccess = 'report_access';

    public function type(): PackageFeatureType
    {
        return match ($this) {
            self::DedicatedWebsite,
            self::DomainIncluded,
            self::HostingIncluded,
            self::ApiAccess,
            self::FulfillmentEnabled,
            self::CourierEnabled,
            self::WholesaleEnabled,
            self::DropshippingEnabled => PackageFeatureType::Boolean,

            self::ProductPublishLimit,
            self::OrderLimit,
            self::StaffLimit,
            self::WebsiteLimit,
            self::SmsQuota => PackageFeatureType::Limit,

            self::SupportLevel,
            self::ReportAccess => PackageFeatureType::Text,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DedicatedWebsite => 'Dedicated website',
            self::DomainIncluded => 'Domain included',
            self::HostingIncluded => 'Hosting included',
            self::ProductPublishLimit => 'Products that can be published',
            self::OrderLimit => 'Orders per month',
            self::StaffLimit => 'Staff members',
            self::WebsiteLimit => 'Websites',
            self::SmsQuota => 'SMS per month',
            self::ApiAccess => 'API access',
            self::FulfillmentEnabled => 'Fulfillment service',
            self::CourierEnabled => 'Courier service',
            self::WholesaleEnabled => 'Bulk wholesale purchasing',
            self::DropshippingEnabled => 'Dropshipping',
            self::SupportLevel => 'Support level',
            self::ReportAccess => 'Report access',
        };
    }

    /**
     * What an account gets when a package says nothing about this feature.
     *
     * Facilities default **off** and limits default to **zero**, so a package
     * that forgets to mention a feature grants nothing rather than everything.
     * A misconfigured package should under-deliver visibly, not hand out free
     * websites.
     *
     * The two exceptions are the business methods themselves: §10.3 says an
     * active account may use dropshipping, wholesale, or both, so those are on
     * unless a package deliberately withdraws them.
     */
    public function default(): bool|int|string
    {
        return match ($this) {
            self::WholesaleEnabled, self::DropshippingEnabled => true,

            self::DedicatedWebsite,
            self::DomainIncluded,
            self::HostingIncluded,
            self::ApiAccess,
            self::FulfillmentEnabled,
            self::CourierEnabled => false,

            self::ProductPublishLimit,
            self::OrderLimit,
            self::StaffLimit,
            self::WebsiteLimit,
            self::SmsQuota => 0,

            self::SupportLevel => 'standard',
            self::ReportAccess => 'basic',
        };
    }
}
