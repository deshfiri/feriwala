import { usePage } from '@inertiajs/react';
import type { LocaleState, TranslationTree } from '@/types';

type TranslationProps = {
    locale?: LocaleState;
    translations?: TranslationTree;
};

/**
 * Look up a dotted key such as `common.actions.save`.
 *
 * Returns the key itself when a line is missing. That is deliberate: a visible
 * `common.actions.save` in the interface is an obvious bug that gets fixed,
 * whereas silently rendering an empty string produces a blank button nobody
 * notices until a user reports it.
 */
function lookup(tree: TranslationTree, key: string): string | undefined {
    const value = key
        .split('.')
        .reduce<string | TranslationTree | undefined>(
            (node, segment) =>
                typeof node === 'object' && node !== null
                    ? node[segment]
                    : undefined,
            tree,
        );

    return typeof value === 'string' ? value : undefined;
}

/**
 * Substitute `:name` style placeholders, matching Laravel's own syntax so a
 * line reads the same in PHP and in TSX.
 *
 * One pass, longest placeholder first. Replacing one token at a time let `:to`
 * match inside `:total`, so "Showing :from–:to of :total" rendered as
 * "Showing 1–25 of 25tal". A single pass also means a substituted value that
 * happens to contain `:something` is never substituted again. Laravel's
 * translator does the same.
 */
function interpolate(
    line: string,
    replacements: Record<string, string | number>,
): string {
    const tokens = Object.keys(replacements).sort(
        (a, b) => b.length - a.length,
    );

    if (tokens.length === 0) {
        return line;
    }

    const pattern = new RegExp(
        `:(${tokens.map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'g',
    );

    return line.replace(pattern, (_match, token: string) =>
        String(replacements[token]),
    );
}

export function useTranslation() {
    const page = usePage<TranslationProps>();
    const translations = page.props.translations ?? {};
    const locale = page.props.locale;

    const t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ): string => {
        const line = lookup(translations, key);

        if (line === undefined) {
            return key;
        }

        return interpolate(line, replacements);
    };

    return {
        t,
        locale: locale?.current ?? 'en',
        direction: locale?.direction ?? 'ltr',
        available: locale?.available ?? [],
    };
}
