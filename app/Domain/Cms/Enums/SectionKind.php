<?php

namespace App\Domain\Cms\Enums;

use App\Domain\Package\Enums\PackageFeature;

/**
 * Every section kind the public landing page's schema supports (§4, §34).
 *
 * Declaring all 22 here costs nothing and keeps the schema forward-compatible
 * with Stage 7's editing UI; only the kinds listed under
 * {@see SectionKind::hasRenderer()} have a validated content schema
 * (App\Domain\Cms\Support\SectionContentValidator) and a React renderer
 * today. A kind without one cannot be saved into a page yet — attempting to
 * is refused at the validator, not silently accepted as an empty section.
 */
enum SectionKind: string
{
    case HeaderNav = 'header_nav';
    case AnnouncementStrip = 'announcement_strip';
    case Hero = 'hero';
    case PlatformIntroduction = 'platform_introduction';
    case About = 'about';
    case Benefits = 'benefits';
    case HowItWorks = 'how_it_works';
    case Dropshipping = 'dropshipping';
    case Wholesale = 'wholesale';
    case PartnerWebsites = 'partner_websites';
    case SupplierOpportunity = 'supplier_opportunity';
    case PackagePreview = 'package_preview';
    case FeatureGrid = 'feature_grid';
    case Statistics = 'statistics';
    case Video = 'video';
    case Testimonials = 'testimonials';
    case ClientsPartners = 'clients_partners';
    case Faq = 'faq';
    case Contact = 'contact';
    case Cta = 'cta';
    case LegalLinks = 'legal_links';
    case Footer = 'footer';

    /**
     * Kinds with a real validated content schema and React renderer.
     *
     * @return array<int, self>
     */
    public static function implemented(): array
    {
        return [
            self::HeaderNav,
            self::Hero,
            self::PlatformIntroduction,
            self::Benefits,
            self::HowItWorks,
            self::Dropshipping,
            self::Wholesale,
            self::PartnerWebsites,
            self::SupplierOpportunity,
            self::PackagePreview,
            self::Faq,
            self::Cta,
            self::Footer,
        ];
    }

    public function hasRenderer(): bool
    {
        return in_array($this, self::implemented(), true);
    }

    /**
     * A human-readable name for the admin section picker (Stage 7). Plain
     * English, like {@see PackageFeature::label()}
     * — an editor's-eyes-only label for choosing a kind, not content a
     * visitor ever reads.
     */
    public function label(): string
    {
        return match ($this) {
            self::HeaderNav => 'Header navigation',
            self::AnnouncementStrip => 'Announcement strip',
            self::Hero => 'Hero',
            self::PlatformIntroduction => 'Platform introduction',
            self::About => 'About',
            self::Benefits => 'Benefits',
            self::HowItWorks => 'How it works',
            self::Dropshipping => 'Dropshipping',
            self::Wholesale => 'Wholesale',
            self::PartnerWebsites => 'Dedicated Partner Websites',
            self::SupplierOpportunity => 'Supplier opportunity',
            self::PackagePreview => 'Package preview',
            self::FeatureGrid => 'Feature grid',
            self::Statistics => 'Statistics',
            self::Video => 'Video',
            self::Testimonials => 'Testimonials',
            self::ClientsPartners => 'Clients & partners',
            self::Faq => 'FAQ',
            self::Contact => 'Contact',
            self::Cta => 'Call to action',
            self::LegalLinks => 'Legal links',
            self::Footer => 'Footer',
        };
    }
}
