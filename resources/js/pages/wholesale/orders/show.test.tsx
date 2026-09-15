// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WholesaleOrderDetail } from '@/types/orders';

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

const { default: WholesaleOrder } = await import('./show');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

function order(
    overrides: Partial<WholesaleOrderDetail> = {},
): WholesaleOrderDetail {
    return {
        id: '01ORDER',
        reference: 'ORD-260915-TEST0001',
        status: 'payment_pending',
        status_tone: 'warning',
        payment_state: 'awaiting',
        placed_at: '2026-09-15T10:00:00+06:00',
        paid_at: null,
        cancelled_at: null,
        placed_by: 'Karim Uddin',
        total: money(2000000),
        item_count: 1,
        lines: [
            {
                id: '01LINE',
                name: 'Electric kettle',
                sku: 'FW-KT-17',
                variant: null,
                quantity: 10,
                unit_price: money(200000),
                subtotal: money(2000000),
                discount: money(0),
                tax: money(0),
                total: money(2000000),
            },
        ],
        totals: {
            subtotal: money(2000000),
            discount: money(0),
            delivery: money(0),
            tax: money(0),
            tax_included: money(0),
            total: money(2000000),
        },
        coupon_code: null,
        customer_note: null,
        addresses: { billing: null, shipping: null },
        payment: {
            reference: 'PAY-260915-TEST0001',
            gateway: 'SSLCommerz',
            expires_at: '2026-09-15T10:15:00+06:00',
        },
        stock: { state: 'held', held_until: '2026-09-15T10:15:00+06:00' },
        timeline: [
            {
                status: 'payment_pending',
                at: '2026-09-15T10:00:00+06:00',
                note: 'Waiting for your payment.',
            },
        ],
        invoice: null,
        ...overrides,
    };
}

/**
 * A wholesale order as its account follows it (§10.2, P4-12): the page says
 * where the order stands and what comes next, for every state an order can be
 * in between checkout and delivery today.
 */
describe('wholesale order page', () => {
    it('waits for payment while the stock is held, and has no invoice yet', () => {
        render(<WholesaleOrder order={order()} />);

        const state = screen.getByRole('status');

        expect(state).toHaveTextContent('orders.states.awaiting_title');
        expect(state).toHaveTextContent('orders.states.awaiting_held');
        expect(screen.getByText('orders.invoice.none')).toBeInTheDocument();
        expect(
            screen.getByText('orders.stock_states.held'),
        ).toBeInTheDocument();
    });

    it('says when the time to pay has run out', () => {
        render(
            <WholesaleOrder
                order={order({
                    stock: { state: 'released', held_until: null },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.states.awaiting_lapsed',
        );
    });

    it('says the payment is being confirmed', () => {
        render(
            <WholesaleOrder order={order({ payment_state: 'confirming' })} />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.states.confirming_title',
        );
    });

    it('shows a paid order with a way to its invoice', () => {
        render(
            <WholesaleOrder
                order={order({
                    status: 'paid',
                    status_tone: 'success',
                    payment_state: 'paid',
                    paid_at: '2026-09-15T10:05:00+06:00',
                    stock: { state: 'committed', held_until: null },
                    invoice: { id: '01INVOICE', number: 'INV-2026-000042' },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.states.paid_title',
        );

        const invoice = screen.getByRole('link', {
            name: /orders\.invoice\.view/,
        });

        expect(invoice).toHaveAttribute(
            'href',
            expect.stringContaining('01INVOICE'),
        );
        expect(
            screen.queryByText('orders.invoice.none'),
        ).not.toBeInTheDocument();
    });

    it('explains an order held because its stock could not be secured', () => {
        render(
            <WholesaleOrder
                order={order({
                    status: 'on_hold',
                    payment_state: 'paid',
                    stock: { state: 'attention', held_until: null },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.states.on_hold_title',
        );
        expect(
            screen.getByText('orders.stock_states.attention'),
        ).toBeInTheDocument();
    });

    it('explains a payment that arrived after the order was cancelled', () => {
        render(
            <WholesaleOrder
                order={order({
                    status: 'cancelled',
                    status_tone: 'danger',
                    payment_state: 'reconciliation',
                    cancelled_at: '2026-09-15T10:16:00+06:00',
                    stock: { state: 'released', held_until: null },
                })}
            />,
        );

        const state = screen.getByRole('status');

        expect(state).toHaveTextContent('orders.states.reconciliation_title');
        expect(state).not.toHaveTextContent('orders.states.cancelled_title');
    });

    it('says a cancelled order holds no stock', () => {
        render(
            <WholesaleOrder
                order={order({
                    status: 'cancelled',
                    status_tone: 'danger',
                    payment_state: 'cancelled',
                    stock: { state: 'released', held_until: null },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.states.cancelled_title',
        );
    });

    it('lists the timeline with the notes written for the account', () => {
        render(
            <WholesaleOrder
                order={order({
                    timeline: [
                        {
                            status: 'payment_pending',
                            at: '2026-09-15T10:00:00+06:00',
                            note: 'Waiting for your payment.',
                        },
                        {
                            status: 'paid',
                            at: '2026-09-15T10:05:00+06:00',
                            note: null,
                        },
                    ],
                })}
            />,
        );

        const note = screen.getByText('Waiting for your payment.');
        const timeline = note.closest('ol');

        expect(timeline).not.toBeNull();
        expect(
            within(timeline as HTMLElement).getByText(
                'orders.statuses.payment_pending',
            ),
        ).toBeInTheDocument();
        expect(
            within(timeline as HTMLElement).getByText('orders.statuses.paid'),
        ).toBeInTheDocument();
    });
});
