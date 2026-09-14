// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    CatalogAbilities,
    Paginator,
    ProductBulkOptions,
    ProductRow,
} from '@/types';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url, ...rest },
                children as never,
            ),
        router: { get: vi.fn(), post: vi.fn(), on: vi.fn(() => () => {}) },
        usePage: () => ({ props: { translations: {}, permissions: {} } }),
    };
});

const { default: AdminProducts } = await import('./index');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

const emptyPage: Paginator<ProductRow> = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 25,
    from: null,
    to: null,
    total: 0,
};

const noBulk: ProductBulkOptions = {
    max: 100,
    transitions: [],
    enable_channels: false,
    disable_channels: false,
    feature: false,
    assign: false,
};

/**
 * The UI layer of §12 (P3-16): what the product list renders is decided by the
 * abilities the server sends. Translations are absent here, so each line
 * renders as its key — which is exactly what these assertions read.
 */
describe('the central product list', () => {
    let container: HTMLDivElement;
    let root: Root;

    const render = (can: CatalogAbilities, bulk: ProductBulkOptions) =>
        act(() =>
            root.render(
                <AdminProducts
                    products={emptyPage}
                    filters={{
                        status: null,
                        category: null,
                        brand: null,
                        channel: null,
                        featured: null,
                    }}
                    filter_options={{ categories: [], brands: [] }}
                    can={can}
                    bulk={bulk}
                />,
            ),
        );

    beforeEach(() => {
        globalThis.IS_REACT_ACT_ENVIRONMENT = true;
        window.history.replaceState({}, '', '/admin/catalog/products');
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
    });

    afterEach(() => {
        act(() => root.unmount());
        container.remove();
    });

    it('renders no create control, no bulk selection and no invitation to create for someone who may only view', () => {
        render({ create: false, edit: false, delete: false }, noBulk);

        expect(
            container.querySelector('a[href="/admin/catalog/products/create"]'),
        ).toBeNull();
        expect(container.textContent).not.toContain('catalog.products.create');
        expect(container.querySelector('[role="checkbox"]')).toBeNull();
        expect(container.textContent).toContain(
            'catalog.products.empty_help_read_only',
        );
    });

    it('renders the create control and the invitation for someone who may author', () => {
        render(
            { create: true, edit: true, delete: true },
            { ...noBulk, feature: true, assign: true },
        );

        expect(
            container.querySelector('a[href="/admin/catalog/products/create"]'),
        ).not.toBeNull();
        expect(container.textContent).toContain('catalog.products.empty_help');
        expect(container.textContent).not.toContain(
            'catalog.products.empty_help_read_only',
        );
    });

    it('never renders an import control, for anyone', () => {
        render(
            { create: true, edit: true, delete: true },
            { ...noBulk, feature: true, assign: true },
        );

        expect(container.textContent?.toLowerCase()).not.toContain('import');
    });
});
