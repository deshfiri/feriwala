<?php

namespace App\Domain\Address\Enums;

/**
 * The two actor kinds `shared_addresses` holds addresses for.
 *
 * A plain string column, not an Eloquent morph map — nothing here needs the
 * relation itself, only the pair to scope a query by (§31.3).
 */
enum AddressOwnerType: string
{
    case BusinessAccount = 'business_account';
    case Supplier = 'supplier';

    /**
     * The `type` values this owner kind may hold — what a form's `type`
     * field is validated against (`Rule::in($ownerType->allowedAddressTypes())`).
     *
     * @return list<string>
     */
    public function allowedAddressTypes(): array
    {
        return match ($this) {
            self::BusinessAccount => array_map(fn (ClientAddressType $type) => $type->value, ClientAddressType::cases()),
            self::Supplier => array_map(fn (SupplierAddressType $type) => $type->value, SupplierAddressType::cases()),
        };
    }
}
