// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WebsiteSelection } from '@/types/website';

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
        Form: ({
            action,
            children,
        }: {
            action: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, method: 'post' },
                children({ errors: {}, processing: false }) as never,
            ),
        router: { reload: vi.fn(), get: vi.fn(), visit: vi.fn() },
        usePage: () => ({
            props: { translations: {} },
            url: '/websites/x/products',
        }),
    };
});

const { default: WebsiteProducts } = await import('./products');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

function selection(
    overrides: Partial<WebsiteSelection> = {},
): WebsiteSelection {
    return {
        id: '01SELECTION',
        product: {
            id: '01PRODUCT',
            name: 'Cotton panjabi',
            sku: 'FW-1043',
            image: null,
        },
        status: 'selected',
        status_label: 'Selected',
        sync_status: 'pending',
        sync_label: 'Waiting to synchronise',
        last_synced_at: null,
        sync_error: null,
        price: money(250000),
        promotional_price: null,
        promo_title: null,
        marketing_description: null,
        is_featured: false,
        display_order: 0,
        category: null,
        terms: {
            allows_user_pricing: true,
            minimum: money(200000),
            maximum: money(400000),
            suggested: money(250000),
            max_margin_percent: null,
            margin_ceiling: null,
            locked_fields: [],
        },
        published_at: null,
        ...overrides,
    };
}

const paginator = (data: WebsiteSelection[]) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 20,
    from: data.length > 0 ? 1 : null,
    to: data.length > 0 ? data.length : null,
    total: data.length,
});

const baseProps = {
    website: {
        id: '01WEBSITE',
        name: 'Nasrin Fashion',
        host: 'nasrin.feriwala.shop',
        status: 'active',
        status_label: 'Active',
        is_live: true,
        connection_health: 'healthy',
        last_synced_at: null,
        outstanding_charges: 0,
        account: null,
        created_at: '2026-09-16T10:00:00+06:00',
    },
    filters: { search: null, status: null, category: null },
    categories: [],
    statuses: [
        { value: 'selected', label: 'Selected' },
        { value: 'published', label: 'Published' },
        { value: 'unpublished', label: 'Unpublished' },
    ],
    publishing: { limit: 2, used: 1, remaining: 1 },
};

describe('a storefront’s products', () => {
    it('shows each product’s price, bounds and where the shop’s copy stands', () => {
        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([selection()])}
            />,
        );

        expect(screen.getAllByText('Cotton panjabi').length).toBeGreaterThan(0);
        expect(
            screen.getAllByText('Waiting to synchronise').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByText('website.products.bounds').length,
        ).toBeGreaterThan(0);
    });

    it('offers to publish a selected product and to unpublish one on sale', () => {
        const { unmount } = render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([selection()])}
            />,
        );

        expect(
            screen.getAllByRole('button', { name: 'website.products.publish' })
                .length,
        ).toBeGreaterThan(0);

        unmount();

        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([
                    selection({
                        status: 'published',
                        status_label: 'Published',
                    }),
                ])}
            />,
        );

        expect(
            screen.getAllByRole('button', {
                name: 'website.products.unpublish',
            }).length,
        ).toBeGreaterThan(0);
    });

    it('says a product has no price rather than showing nothing', () => {
        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([selection({ price: null })])}
            />,
        );

        expect(
            screen.getAllByText('website.products.no_price').length,
        ).toBeGreaterThan(0);
    });

    it('sends a partner to the catalogue to choose, never to create', () => {
        render(<WebsiteProducts {...baseProps} selections={paginator([])} />);

        expect(
            screen.getAllByRole('link', { name: 'website.products.choose' })
                .length,
        ).toBeGreaterThan(0);
        expect(
            screen.queryByRole('link', { name: /create/i }),
        ).not.toBeInTheDocument();
    });
});
