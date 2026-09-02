<?php

namespace App\Support\Localization;

/**
 * The languages the ERP interface is available in (decision D6).
 *
 * English is the default. Bangla is a first-class alternative, not an
 * afterthought — SMS templates are required in both languages (§30.2), and the
 * interface carries a toggle.
 */
enum Locale: string
{
    case English = 'en';
    case Bangla = 'bn';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * The language's own name, shown in the switcher. A speaker looking for
     * Bangla should see "বাংলা", not "Bengali".
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Bangla => 'বাংলা',
        };
    }

    public function englishName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Bangla => 'Bangla',
        };
    }

    /**
     * Writing direction. Both current locales are left-to-right; the method
     * exists so layout code asks rather than assumes.
     */
    public function direction(): string
    {
        return 'ltr';
    }

    /**
     * Resolve a locale string, falling back to the default rather than throwing —
     * a stale cookie or a hand-edited preference must not break a page load.
     */
    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $locale) => ['value' => $locale->value, 'label' => $locale->nativeName()],
            self::cases(),
        );
    }
}
