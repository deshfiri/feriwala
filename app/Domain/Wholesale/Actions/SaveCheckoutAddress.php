<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\UserAddress;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Keep the billing or shipping address a person checks out with (§14, P4-7).
 *
 * One current address of each kind per person, edited in place. That is safe
 * because an order keeps its own snapshot of where it was billed and delivered
 * ({@see UserAddress::toSnapshot()}); changing an address later never rewrites a
 * past order.
 *
 * The person's row is locked while the address is found or created, so two
 * submissions at once leave one address, not two.
 */
class SaveCheckoutAddress
{
    /** The fields a person gives; the country is Bangladesh. */
    public const FIELDS = ['contact_name', 'contact_mobile', 'line_1', 'line_2', 'area', 'city', 'district', 'postcode'];

    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function handle(User $user, AddressType $type, array $fields): UserAddress
    {
        if (! in_array($type, [AddressType::Billing, AddressType::Shipping], true)) {
            throw new InvalidArgumentException('Checkout keeps billing and shipping addresses only.');
        }

        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $this->database->transaction(function () use ($user, $type, $values) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $address = UserAddress::query()->currentFor($user->id, $type)->first();

            if ($address === null) {
                return UserAddress::create([
                    ...$values,
                    'user_id' => $user->id,
                    'type' => $type,
                    'country' => 'BD',
                    'is_default' => true,
                ]);
            }

            $address->forceFill($values)->save();

            return $address;
        });
    }
}
