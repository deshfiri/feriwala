<?php

namespace App\Domain\Account\Enums;

/**
 * How a person describes themselves at registration (§5.2).
 *
 * A closed set rather than free text, for the same reason countries are: a
 * column that accepts anything accumulates "M", "male", "Male " and "পুরুষ" for
 * one answer, and no report can group them afterwards.
 *
 * `Other` is present because Bangladesh recognises a third gender in law, and a
 * form that offers two options tells a citizen the system does not. `Undisclosed`
 * is separate from it and from null: it records that the question was put and
 * declined, which is not the same as never having been asked.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case Undisclosed = 'undisclosed';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Other => 'Other',
            self::Undisclosed => 'Prefer not to say',
        };
    }

    /**
     * Options for a picker, in the order they are offered.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $gender) => ['value' => $gender->value, 'label' => $gender->label()],
            self::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
