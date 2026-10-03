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
        useForm: () => ({ post: vi.fn(), processing: false, errors: {} }),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WholesaleOrder } = await import('./show');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
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
        account_type: 'conditional',
        total: money('20000.00'),
        item_count: 1,
        lines: [
            {
                id: '01LINE',
                name: 'Electric kettle',
                sku: 'FW-KT-17',
                variant: null,
                quantity: 10,
                unit_price: money('2000.00'),
                subtotal: money('20000.00'),
                discount: money('0.00'),
                tax: money('0.00'),
                total: money('20000.00'),
                resale_amount: null,
            },
        ],
        totals: {
            subtotal: money('20000.00'),
            discount: money('0.00'),
            delivery: money('0.00'),
            tax: money('0.00'),
            tax_included: money('0.00'),
            total: money('20000.00'),
        },
        coupon_code: null,
        customer_note: null,
        intended_resale_channel: null,
        addresses: { billing: null, shipping: null },
        payment: {
            reference: 'PAY-260915-TEST0001',
            gateway: 'SSLCommerz',
            expires_at: '2026-09-15T10:15:00+06:00',
            window_open: true,
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

    it('says the time to pay has run out when the server says the window closed', () => {
        // The stock may not have been released yet; the server's answer wins
        // over a held-until time the browser's clock would have to judge.
        render(
            <WholesaleOrder
                order={order({
                    payment: {
                        reference: 'PAY-260915-TEST0001',
                        gateway: 'SSLCommerz',
                        expires_at: '2026-09-15T10:14:00+06:00',
                        window_open: false,
                    },
                })}
            />,
        );

        const state = screen.getByRole('status');

        expect(state).toHaveTextContent('orders.states.awaiting_lapsed');
        expect(state).not.toHaveTextContent('orders.states.awaiting_held');
    });

    it('offers to continue to payment and to cancel when the server allows both', () => {
        render(
            <WholesaleOrder
                order={order()}
                can={{ pay: true, cancel: true }}
            />,
        );

        const pay = screen.getByRole('button', { name: 'orders.actions.pay' });

        expect(pay.closest('form')).toHaveAttribute(
            'action',
            expect.stringContaining('/wholesale/orders/01ORDER/payment'),
        );
        expect(
            screen.getByRole('button', { name: 'orders.actions.cancel' }),
        ).toBeInTheDocument();
    });

    it('offers no payment or cancellation the server does not allow', () => {
        render(
            <WholesaleOrder
                order={order({
                    status: 'paid',
                    payment_state: 'paid',
                    paid_at: '2026-09-15T10:05:00+06:00',
                })}
                can={{ pay: false, cancel: false }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'orders.actions.pay' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'orders.actions.cancel' }),
        ).not.toBeInTheDocument();
    });

    it('shows the resale channel the buyer gave, for reporting', () => {
        render(
            <WholesaleOrder
                order={order({ intended_resale_channel: 'marketplace' })}
            />,
        );

        expect(
            screen.getByText('orders.resale.channels.marketplace'),
        ).toBeInTheDocument();
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
