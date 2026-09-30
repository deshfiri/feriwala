import type { LocaleState } from '@/types/localization';

/**
 * Keeps `<html lang>`/`dir` in step with the server's own locale prop after a
 * client-side Inertia visit.
 *
 * Those two attributes are only ever painted once, server-side, by the Blade
 * shell (`app()->getLocale()` at the time of a full page load). Switching
 * language through `LanguageSwitcher` is an ordinary Inertia visit — it swaps
 * the page component, never the `<html>` tag — so without this, the
 * attribute stayed on whatever locale the last hard load happened to be in
 * while the page itself had already switched to Bangla. `:lang(bn)` in
 * app.css, which is what selects `--font-bangla`, never matched, and Bangla
 * text fell through to Instrument Sans (no Bengali glyphs) instead.
 */
export function syncDocumentLocale(props: Record<string, unknown>): void {
    const locale = props.locale as LocaleState | undefined;

    if (!locale) {
        return;
    }

    document.documentElement.lang = locale.current;
    document.documentElement.dir = locale.direction;
}
