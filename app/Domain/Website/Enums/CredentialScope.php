<?php

namespace App\Domain\Website\Enums;

/**
 * What a storefront credential may do (contract §3.4, §17.3, P5-17).
 *
 * The contract's allow-list, and nothing else. There is deliberately **no**
 * wallet, ledger, KYC, commission or user scope: those are not omissions to be
 * filled in later, the surface does not exist to be granted (D11).
 *
 * Least privilege by default. A credential is issued with the scopes a
 * storefront needs and no more, and a write scope granted to a credential that
 * only reads is a scope somebody can misuse the day the key leaks.
 */
enum CredentialScope: string
{
    case CatalogRead = 'catalog:read';
    case InventoryRead = 'inventory:read';
    case OrdersWrite = 'orders:write';
    case OrdersRead = 'orders:read';
    case CustomersWrite = 'customers:write';
    case ReturnsWrite = 'returns:write';

    /**
     * The scopes a new storefront is issued unless somebody chooses otherwise.
     *
     * Reads only: the order and customer endpoints arrive with order intake
     * (P5-23), and a credential should not carry authority over a surface that
     * does not yet exist.
     *
     * @return array<int, self>
     */
    public static function defaults(): array
    {
        return [self::CatalogRead, self::InventoryRead];
    }

    public function label(): string
    {
        return match ($this) {
            self::CatalogRead => 'Read the catalogue',
            self::InventoryRead => 'Read stock availability',
            self::OrdersWrite => 'Submit orders',
            self::OrdersRead => 'Read its own orders',
            self::CustomersWrite => 'Create and update its own customers',
            self::ReturnsWrite => 'Submit return and refund requests',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope) => $scope->value, self::cases());
    }
}
