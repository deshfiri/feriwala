// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { pageProps } = vi.hoisted(() => ({
    pageProps: {
        translations: {
            common: {
                table: {
                    showing: 'Showing :from–:to of :total',
                    showing_bn: ':total টির মধ্যে :from–:to দেখানো হচ্ছে',
                },
            },
            catalog: { greeting: 'Hello :name, :name_full' },
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
}));

const { useTranslation } = await import('./use-translation');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

type Translate = ReturnType<typeof useTranslation>['t'];

let translate: Translate | null = null;

function Harness() {
    translate = useTranslation().t;

    return null;
}

/**
 * The pagination footer rendered "Showing 1–25 of 25tal": `:to` was replaced
 * inside `:total` because placeholders were substituted one at a time, in the
 * order they were passed.
 */
describe('useTranslation placeholders', () => {
    let container: HTMLDivElement;
    let root: Root;

    beforeEach(() => {
        globalThis.IS_REACT_ACT_ENVIRONMENT = true;
        container = document.createElement('div');
        root = createRoot(container);
        act(() => root.render(<Harness />));
    });

    afterEach(() => {
        act(() => root.unmount());
        translate = null;
    });

    it('fills :to and :total in the same line', () => {
        expect(
            translate!('common.table.showing', { from: 1, to: 25, total: 250 }),
        ).toBe('Showing 1–25 of 250');
    });

    it('does not depend on the order the replacements are passed in', () => {
        expect(
            translate!('common.table.showing', { total: 1, to: 1, from: 1 }),
        ).toBe('Showing 1–1 of 1');
    });

    it('works where the longer placeholder comes first in the line', () => {
        expect(
            translate!('common.table.showing_bn', {
                from: 1,
                to: 10,
                total: 42,
            }),
        ).toBe('42 টির মধ্যে 1–10 দেখানো হচ্ছে');
    });

    it('never substitutes inside a value it has already put in', () => {
        expect(
            translate!('catalog.greeting', {
                name: ':name_full',
                name_full: 'Karim Traders',
            }),
        ).toBe('Hello :name_full, Karim Traders');
    });
});
