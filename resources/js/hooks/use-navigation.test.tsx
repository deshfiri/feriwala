// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { page } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

const { useNavigation } = await import('./use-navigation');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

let titles: string[] = [];

function Harness() {
    titles = useNavigation()
        .searchGroups.flatMap((group) => group.items)
        .map((item) => item.title);

    return null;
}

const ADMIN_CATALOGUE = [
    'nav.products',
    'nav.product_categories',
    'nav.brands',
    'nav.attributes',
];

/**
 * Navigation must match what the endpoints allow (§12): the sidebar and the
 * command palette read the same shared contract the server computes from the
 * catalogue policy, so a partner is never shown a door that opens onto a
 * refusal.
 */
describe('catalogue navigation', () => {
    let root: Root;

    const renderFor = (props: Record<string, unknown>) => {
        page.props = { translations: {}, ...props };
        act(() => root.render(<Harness />));
    };

    beforeEach(() => {
        globalThis.IS_REACT_ACT_ENVIRONMENT = true;
        root = createRoot(document.createElement('div'));
    });

    afterEach(() => {
        act(() => root.unmount());
        titles = [];
    });

    it('gives a partner their own catalogue and none of the administration', () => {
        renderFor({
            permissions: { 'catalog.view': false },
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: true,
                managesStaff: false,
            },
        });

        expect(titles).toContain('nav.wholesale_catalogue');
        expect(titles).toContain('nav.dropshipping_catalogue');
        ADMIN_CATALOGUE.forEach((title) => expect(titles).not.toContain(title));
    });

    it('drops a partner catalogue their package does not include', () => {
        renderFor({
            permissions: {},
            account: {
                status: 'active',
                allowsWholesale: false,
                allowsDropshipping: false,
                managesStaff: false,
            },
        });

        expect(titles).not.toContain('nav.wholesale_catalogue');
        expect(titles).not.toContain('nav.dropshipping_catalogue');
    });

    it('gives staff who may view the catalogue its administration, and no partner doors', () => {
        renderFor({ permissions: { 'catalog.view': true }, account: null });

        ADMIN_CATALOGUE.forEach((title) => expect(titles).toContain(title));
        expect(titles).not.toContain('nav.wholesale_catalogue');
    });

    it('gives staff without the catalogue nothing of it', () => {
        renderFor({ permissions: { 'sms.view': true }, account: null });

        ADMIN_CATALOGUE.forEach((title) => expect(titles).not.toContain(title));
    });
});
