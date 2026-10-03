<?php

namespace App\Domain\Account\Enums;

/**
 * Whether a trading business funds a wholesale order's product cost upfront,
 * or only after delivery.
 *
 * Not a state machine: an administrator sets this directly, and it moves back
 * and forth freely rather than through a defined sequence of moves — unlike
 * {@see AccountStatus}, which only ever advances.
 *
 * - **Conditional**: the reseller pays the full order (product + delivery)
 *   upfront, exactly as every wholesale order already works today.
 * - **NonConditional**: the reseller pays only the delivery charge upfront;
 *   Banij recovers the product cost from the COD amount collected at
 *   delivery, once that collection is confirmed.
 */
enum AccountType: string
{
    case Conditional = 'conditional';
    case NonConditional = 'non_conditional';

    public function label(): string
    {
        return match ($this) {
            self::Conditional => 'Conditional',
            self::NonConditional => 'Non-conditional',
        };
    }

    /**
     * Options for an admin picker, in the order they are offered.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
