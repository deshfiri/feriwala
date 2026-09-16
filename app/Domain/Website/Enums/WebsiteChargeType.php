<?php

namespace App\Domain\Website\Enums;

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Wallet\Enums\LedgerTransactionType;

/**
 * What a website is charged for (§16.2, §24, P5-10).
 *
 * Each maps to the fee rule that prices it and to the ledger type that records
 * it, so a charge cannot be priced by one rule and posted as another. The
 * mapping lives here rather than at three call sites for exactly that reason.
 */
enum WebsiteChargeType: string
{
    /** Building the storefront. Charged once. */
    case Setup = 'setup';

    /** The domain name, for a term (D9 — registered by hand). */
    case Domain = 'domain';

    /** Hosting, for a term. */
    case Hosting = 'hosting';

    /** Work on a running website, charged when it is done. */
    case Maintenance = 'maintenance';

    public function feeType(): FeeType
    {
        return match ($this) {
            self::Setup => FeeType::WebsiteSetup,
            self::Domain => FeeType::WebsiteDomain,
            self::Hosting => FeeType::WebsiteHosting,
            self::Maintenance => FeeType::WebsiteMaintenance,
        };
    }

    public function ledgerType(): LedgerTransactionType
    {
        return match ($this) {
            self::Setup => LedgerTransactionType::WebsiteSetupDebit,
            self::Domain => LedgerTransactionType::DomainChargeDebit,
            self::Hosting => LedgerTransactionType::HostingChargeDebit,
            self::Maintenance => LedgerTransactionType::MaintenanceChargeDebit,
        };
    }

    /**
     * Whether this charge falls due again each term.
     */
    public function recurs(): bool
    {
        return $this === self::Domain || $this === self::Hosting;
    }

    public function label(): string
    {
        return match ($this) {
            self::Setup => 'Website setup charge',
            self::Domain => 'Domain charge',
            self::Hosting => 'Hosting charge',
            self::Maintenance => 'Maintenance charge',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
