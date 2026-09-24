// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated, WholesaleOrderSummary } from '@/types/orders';

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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WholesaleOrders } = await import('./index');

const summary = (
    overrides: Partial<WholesaleOrderSummary> = {},
): WholesaleOrderSummary => ({
    id: '01ORDER',
    reference: 'ORD-260915-TEST0001',
    status: 'paid',
    status_tone: 'success',
    payment_state: 'paid',
    placed_at: '2026-09-15T10:00:00+06:00',
    total: {
        amount: '20000.00',
        currency: 'BDT',
        formatted: '৳20,000.00',
    },
    item_count: 2,
    ...overrides,
});

const page = (
    data: WholesaleOrderSummary[],
    current = 1,
    last = 1,
): Paginated<WholesaleOrderSummary> => ({
    data,
    current_page: current,
    last_page: last,
    total: data.length,
});

/**
 * The account's wholesale orders (§10.2, P4-12): an empty state that leads back
 * to buying, and a list whose every order opens its own page.
 */
describe('wholesale orders list', () => {
    it('shows an empty state that leads to the wholesale catalogue', () => {
        render(<WholesaleOrders orders={page([])} />);

        expect(screen.getByText('orders.empty')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'orders.browse' }),
        ).toHaveAttribute(
            'href',
            expect.stringContaining('/catalog/wholesale'),
        );
    });

    it('lists each order with its status in words and a way into it', () => {
        render(
            <WholesaleOrders
                orders={page([
                    summary(),
                    summary({
                        id: '01SECOND',
                        reference: 'ORD-260915-TEST0002',
                        status: 'payment_pending',
                        status_tone: 'warning',
                        payment_state: 'awaiting',
                    }),
                ])}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'ORD-260915-TEST0002' }),
        ).toHaveAttribute('href', expect.stringContaining('01SECOND'));
        expect(screen.getByText('orders.statuses.paid')).toBeInTheDocument();
        expect(
            screen.getByText('orders.statuses.payment_pending'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('orders.payment_states.awaiting'),
        ).toBeInTheDocument();
        expect(screen.queryByText('orders.previous')).not.toBeInTheDocument();
    });

    it('pages through a long list', () => {
        render(<WholesaleOrders orders={page([summary()], 2, 3)} />);

        expect(
            screen.getByRole('link', { name: 'orders.previous' }),
        ).toHaveAttribute('href', expect.stringContaining('page=1'));
        expect(
            screen.getByRole('link', { name: 'orders.next' }),
        ).toHaveAttribute('href', expect.stringContaining('page=3'));
    });
});
