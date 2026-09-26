<?php

namespace App\Domain\Cms\Support;

/**
 * Resolves every `{en: string, bn: string|null}` field in a section's stored
 * content down to a single plain string for the current locale, before it
 * ever reaches Inertia — the same "resolve server-side, ship one string"
 * discipline `HandleInertiaRequests::loadTranslations()` already applies to
 * UI copy. A React section renderer never sees a locale object and can never
 * pick the wrong half of one.
 *
 * Falls back to English when a Bangla line is blank, exactly like the
 * translation-file fallback (`array_replace_recursive` over the English
 * tree) — a missing Bangla line shows real words, not an empty string.
 */
class LocalizedContentResolver
{
    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function resolve(array $content, string $locale): array
    {
        return $this->walk($content, $locale);
    }

    protected function walk(mixed $value, string $locale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isLocalizedShape($value)) {
            return $this->pick($value, $locale);
        }

        return array_map(fn ($item) => $this->walk($item, $locale), $value);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function isLocalizedShape(array $value): bool
    {
        return array_key_exists('en', $value)
            && is_string($value['en'])
            && count(array_diff(array_keys($value), ['en', 'bn'])) === 0;
    }

    /**
     * @param  array<string, mixed>  $localized
     */
    protected function pick(array $localized, string $locale): string
    {
        if ($locale === 'bn' && filled($localized['bn'] ?? null)) {
            return $localized['bn'];
        }

        return $localized['en'] ?? '';
    }
}
