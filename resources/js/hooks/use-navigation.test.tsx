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

/**
 * The wholesale cart (§14, P4-4): a door for an account that may buy wholesale,
 * with the count in words, and none for anybody else.
 */
describe('wholesale cart navigation', () => {
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

    it('offers the cart, with its count, to an account that may buy wholesale', () => {
        renderFor({
            permissions: {},
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: false,
                managesStaff: false,
                wholesaleCartLines: 3,
            },
        });

        expect(titles).toContain('nav.wholesale_cart (3)');
    });

    it('offers no cart where wholesale is not included, nor to platform staff', () => {
        renderFor({
            permissions: { 'catalog.view': true },
            account: {
                status: 'active',
                allowsWholesale: false,
                allowsDropshipping: true,
                managesStaff: false,
                wholesaleCartLines: 0,
            },
        });
        expect(
            titles.some((title) => title.startsWith('nav.wholesale_cart')),
        ).toBe(false);

        act(() => root.unmount());
        root = createRoot(document.createElement('div'));

        renderFor({ permissions: { 'catalog.view': true }, account: null });
        expect(
            titles.some((title) => title.startsWith('nav.wholesale_cart')),
        ).toBe(false);
    });
});

/**
 * Wholesale orders (§10.2, P4-12): a door for an account that may buy
 * wholesale, kept while it has orders to follow, and none for platform staff.
 */
describe('wholesale order navigation', () => {
    let root: Root;

    const account = {
        status: 'active',
        allowsDropshipping: false,
        managesStaff: false,
        wholesaleCartLines: 0,
    };

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

    it('offers the orders to an account that may buy wholesale', () => {
        renderFor({
            permissions: {},
            account: {
                ...account,
                allowsWholesale: true,
                hasWholesaleOrders: false,
            },
        });

        expect(titles).toContain('nav.wholesale_orders');
    });

    it('keeps the orders door while there are orders to follow, after wholesale is no longer included', () => {
        renderFor({
            permissions: {},
            account: {
                ...account,
                allowsWholesale: false,
                hasWholesaleOrders: true,
            },
        });

        expect(titles).toContain('nav.wholesale_orders');
        expect(titles).not.toContain('nav.wholesale_cart');
    });

    it('offers no orders door to an account with neither, nor to platform staff', () => {
        renderFor({
            permissions: {},
            account: {
                ...account,
                allowsWholesale: false,
                hasWholesaleOrders: false,
            },
        });
        expect(titles).not.toContain('nav.wholesale_orders');

        act(() => root.unmount());
        root = createRoot(document.createElement('div'));

        renderFor({ permissions: { 'order.view': true }, account: null });
        expect(titles).not.toContain('nav.wholesale_orders');
    });
});

/**
 * Order review (§18.4): staff who may view orders find every order; a partner
 * finds only their own wholesale orders, never the platform's review.
 */
describe('order review navigation', () => {
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

    it('gives staff who may view orders the order review', () => {
        renderFor({ permissions: { 'order.view': true }, account: null });

        expect(titles).toContain('nav.orders');
    });

    it('gives a partner their own orders and not the review, nor staff without the permission', () => {
        renderFor({
            permissions: { 'order.view': false },
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: false,
                managesStaff: false,
                hasWholesaleOrders: true,
            },
        });
        expect(titles).toContain('nav.wholesale_orders');
        expect(titles).not.toContain('nav.orders');

        act(() => root.unmount());
        root = createRoot(document.createElement('div'));

        renderFor({ permissions: { 'inventory.view': true }, account: null });
        expect(titles).not.toContain('nav.orders');
    });
});

/**
 * Allocated stock (§19, P3-30): staff who may view inventory find every
 * allocation; an account finds its own only while it holds some.
 */
describe('allocated stock navigation', () => {
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

    it('gives staff who may view inventory the allocations list', () => {
        renderFor({ permissions: { 'inventory.view': true }, account: null });

        expect(titles).toContain('nav.allocations');
        expect(titles).not.toContain('nav.allocated_stock');
    });

    it('shows an account its allocated stock only while it holds some', () => {
        const account = {
            status: 'active',
            allowsWholesale: true,
            allowsDropshipping: false,
            managesStaff: false,
        };

        renderFor({
            permissions: {},
            account: { ...account, holdsAllocatedStock: true },
        });
        expect(titles).toContain('nav.allocated_stock');
        expect(titles).not.toContain('nav.allocations');

        act(() => root.unmount());
        root = createRoot(document.createElement('div'));

        renderFor({
            permissions: {},
            account: { ...account, holdsAllocatedStock: false },
        });
        expect(titles).not.toContain('nav.allocated_stock');
    });
});

/**
 * The referral configuration (D24): reached by seeing it, never offered to a
 * partner, whose own referrals live on their own page.
 */
describe('referral settings navigation', () => {
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

    it('gives staff who may see the configuration its screen', () => {
        renderFor({
            permissions: { 'referral.view_settings': true },
            account: null,
        });

        expect(titles).toContain('nav.referral_settings');
    });

    it('offers it neither to staff without the permission nor to a partner', () => {
        renderFor({
            permissions: { 'referral.view_settings': false },
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: true,
                managesStaff: false,
            },
        });

        expect(titles).not.toContain('nav.referral_settings');
    });
});

/**
 * Referral doors (D24): the owner's own page, and the platform's records for
 * staff who may see them — never each other's.
 */
describe('referral navigation', () => {
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

    it('gives an owner who may see referrals their own page and no platform records', () => {
        renderFor({
            permissions: { 'referral.view': false },
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: true,
                managesStaff: false,
                viewsReferrals: true,
            },
        });

        expect(titles).toContain('nav.referrals');
        expect(titles).not.toContain('nav.referral_commissions');
    });

    it('gives staff who may see the records the commissions, and no partner page', () => {
        renderFor({ permissions: { 'referral.view': true }, account: null });

        expect(titles).toContain('nav.referral_commissions');
        expect(titles).not.toContain('nav.referrals');
    });

    it('offers the owner page to no member without the permission', () => {
        renderFor({
            permissions: {},
            account: {
                status: 'active',
                allowsWholesale: true,
                allowsDropshipping: true,
                managesStaff: false,
                viewsReferrals: false,
            },
        });

        expect(titles).not.toContain('nav.referrals');
    });
});
