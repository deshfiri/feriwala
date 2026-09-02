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
 */
function interpolate(
    line: string,
    replacements: Record<string, string | number>,
): string {
    return Object.entries(replacements).reduce(
        (result, [token, value]) =>
            result.replaceAll(`:${token}`, String(value)),
        line,
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
