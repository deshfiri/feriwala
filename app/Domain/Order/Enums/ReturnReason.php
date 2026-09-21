<?php

namespace App\Domain\Order\Enums;

/**
 * Why a customer is sending something back (§18.2, contract §6.3, P6-12).
 *
 * A fixed set rather than free text, because the reason decides things: who
 * pays the delivery, whether the goods are fit to sell again, and what a
 * partner's return rate actually means. Whatever the customer wrote in their
 * own words is kept beside it, not instead of it.
 */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case MissingParts = 'missing_parts';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Arrived damaged',
            self::WrongItem => 'Wrong item sent',
            self::NotAsDescribed => 'Not as described',
            self::MissingParts => 'Parts missing',
            self::ChangedMind => 'Changed their mind',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether the goods are suspect on arrival because of why they came back.
     *
     * A hint for whoever inspects them, never a decision: the disposition is
     * theirs to make with the goods in front of them.
     */
    public function suggestsInspection(): bool
    {
        return in_array($this, [self::Damaged, self::MissingParts], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $reason) => $reason->value, self::cases());
    }
}
