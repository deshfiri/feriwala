<?php

namespace App\Domain\Website\Enums;

/**
 * Starting points for the scopes of a new storefront credential (D32, P3 ER-7).
 *
 * A preset only fills in the form. What is issued is exactly the scopes the
 * administrator submits, chosen on purpose every time: a credential never gets
 * a scope because nobody chose otherwise.
 */
enum CredentialPreset: string
{
    /** A read-only catalogue: a preview, or a site that does not sell yet. */
    case Catalogue = 'catalogue';

    /** A storefront that takes orders, customers and returns. */
    case Storefront = 'storefront';

    /**
     * @return list<CredentialScope>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Catalogue => [CredentialScope::CatalogRead],
            self::Storefront => [
                CredentialScope::CatalogRead,
                CredentialScope::InventoryRead,
                CredentialScope::OrdersWrite,
                CredentialScope::OrdersRead,
                CredentialScope::CustomersWrite,
                CredentialScope::ReturnsWrite,
            ],
        };
    }

    public function label(): string
    {
        return (string) __('website.credentials.presets.'.$this->value);
    }
}
