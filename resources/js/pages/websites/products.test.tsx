// @vitest-environment jsdom

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { puts } from '@/test/fake-use-form';
import type { WebsiteSelection } from '@/types/website';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    const { fakeUseForm } = await import('@/test/fake-use-form');

    return {
        useForm: fakeUseForm,
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
        router: {
            reload: vi.fn(),
            get: vi.fn(),
            visit: vi.fn(),
            on: vi.fn(() => () => {}),
        },
        usePage: () => ({
            props: { translations: {} },
            url: '/websites/x/products',
        }),
    };
});

const { default: WebsiteProducts } = await import('./products');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
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
        price: money('2500.00'),
        promotional_price: null,
        promotion_starts_at: null,
        promotion_ends_at: null,
        promotion_applies: false,
        promo_title: null,
        marketing_description: null,
        is_featured: false,
        display_order: 0,
        category: null,
        terms: {
            allows_user_pricing: true,
            minimum: money('2000.00'),
            maximum: money('4000.00'),
            suggested: money('2500.00'),
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

    it('says when a flash deal has not started yet or has ended', () => {
        const { unmount } = render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([
                    selection({
                        promotional_price: money('2200.00'),
                        promotion_starts_at: '2999-01-01T00:00:00+00:00',
                        promotion_applies: false,
                    }),
                ])}
            />,
        );

        expect(
            screen.getAllByText(/website\.products\.promotion_scheduled/)
                .length,
        ).toBeGreaterThan(0);

        unmount();

        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([
                    selection({
                        promotional_price: money('2200.00'),
                        promotion_ends_at: '2000-01-01T00:00:00+00:00',
                        promotion_applies: false,
                    }),
                ])}
            />,
        );

        expect(
            screen.getAllByText(/website\.products\.promotion_ended/).length,
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

describe('editing a selected product', () => {
    beforeEach(() => {
        puts.length = 0;
    });

    const panjabi = () =>
        selection({ id: '01A', price: money('2500.00'), promo_title: 'Eid' });
    const saree = () =>
        selection({
            id: '01B',
            product: {
                id: '01P2',
                name: 'Silk saree',
                sku: 'FW-2001',
                image: null,
            },
            price: money('3600.00'),
            promo_title: null,
        });

    const edit = (index: number) =>
        fireEvent.click(
            screen.getAllByRole('button', { name: 'website.products.edit' })[
                index
            ],
        );

    // Both layouts of the table may be in the document; the dialog is not one of them.
    const priceInputs = () =>
        Array.from(document.querySelectorAll('input[name="price"]'));

    it('opens a dialog for the product clicked, and renders no editor anywhere else', () => {
        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi(), saree()])}
            />,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(priceInputs()).toHaveLength(0);

        edit(0);

        const dialog = screen.getByRole('dialog', { name: 'Cotton panjabi' });

        // The only price field on the page is the one in the dialog: nothing
        // is left under the list, hidden or not.
        expect(priceInputs()).toHaveLength(1);
        expect(dialog).toContainElement(priceInputs()[0] as HTMLElement);
        expect(priceInputs()[0]).toHaveValue('2500.00');
    });

    it('opens another product with its own saved values, not the last one’s edits', () => {
        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi(), saree()])}
            />,
        );

        edit(0);
        fireEvent.change(priceInputs()[0], { target: { value: '3333.00' } });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'common.actions.cancel',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'website.products.discard' }),
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        edit(1);

        expect(
            screen.getByRole('dialog', { name: 'Silk saree' }),
        ).toBeInTheDocument();
        expect(priceInputs()).toHaveLength(1);
        expect(priceInputs()[0]).toHaveValue('3600.00');

        // And the first, reopened, has what was saved, not what was abandoned.
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'common.actions.cancel',
            }),
        );
        edit(0);

        expect(priceInputs()[0]).toHaveValue('2500.00');
    });

    it('closes after a successful save and shows the saved price on the row', () => {
        const { rerender } = render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi()])}
            />,
        );

        edit(0);
        fireEvent.change(priceInputs()[0], { target: { value: '3100.00' } });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'website.products.save',
            }),
        );

        // Still open while the server is deciding.
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        // The server saves and sends the page back with the row as it now is.
        rerender(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([
                    selection({ id: '01A', price: money('3100.00') }),
                ])}
            />,
        );
        act(() => puts[0].succeed());

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getAllByText(/3100/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/2500/)).not.toBeInTheDocument();
    });

    it('stays open with the refusal shown inside it when the save fails', () => {
        render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi()])}
            />,
        );

        edit(0);
        fireEvent.change(priceInputs()[0], { target: { value: '1.00' } });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'website.products.save',
            }),
        );
        act(() => puts[0].fail({ price: 'Below the allowed minimum.' }));

        const dialog = screen.getByRole('dialog');

        expect(
            within(dialog).getByText('Below the allowed minimum.'),
        ).toBeInTheDocument();
        expect(priceInputs()[0]).toHaveValue('1.00');
    });

    it('drops the dialog when its product is no longer on the page', () => {
        const { rerender } = render(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi(), saree()])}
            />,
        );

        edit(1);
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        rerender(
            <WebsiteProducts
                {...baseProps}
                selections={paginator([panjabi()])}
            />,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
