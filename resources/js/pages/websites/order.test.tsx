// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WebsiteOrderDetail } from '@/types/website-order';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url },
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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WebsiteOrder } = await import('./order');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

const order: WebsiteOrderDetail = {
    id: '01ORDER',
    reference: 'ORD-260920-K7M3QX9P',
    storefront_reference: 'SF-2026-000481',
    customer: 'Ayesha Rahman',
    status: 'payment_pending',
    status_label: 'Payment pending',
    status_tone: 'warning',
    payment_state: 'awaiting',
    payment_method: 'online',
    total: money(526000),
    placed_at: '2026-09-20T10:00:00+06:00',
    customer_details: {
        name: 'Ayesha Rahman',
        mobile: '+8801712345678',
        email: 'ayesha@example.test',
        is_guest: true,
    },
    shipping_address: {
        line1: 'House 12, Road 5',
        city: 'Dhaka',
        district: 'Dhaka',
        postcode: '1216',
        country: 'BD',
    },
    billing_address: {
        line1: 'House 12, Road 5',
        city: 'Dhaka',
        country: 'BD',
    },
    customer_note: 'Please call before delivery.',
    paid_at: null,
    cancelled_at: null,
    totals: {
        subtotal: money(520000),
        discount: money(0),
        delivery: money(6000),
        tax: money(0),
        total: money(526000),
    },
    lines: [
        {
            id: '01LINE',
            name: 'Cotton panjabi',
            sku: 'FW-1043-NVY-M',
            variant: 'Navy / M',
            quantity: 2,
            unit_price: money(260000),
            total: money(520000),
            reservation: {
                status: 'active',
                status_label: 'Held',
                expires_at: '2026-09-20T10:15:00+06:00',
            },
        },
    ],
    payment: {
        state: 'awaiting',
        method: 'online',
        expires_at: '2026-09-20T10:14:00+06:00',
        completed_at: null,
    },
    confirmation: null,
    timeline: [
        {
            status: 'payment_pending',
            status_label: 'Payment pending',
            at: '2026-09-20T10:00:00+06:00',
            note: 'Order placed on the website. Waiting for the customer’s payment.',
        },
    ],
};

const website = { id: '01WEBSITE', name: 'Ayesha Fashion' };

// Nothing returnable and nothing returned, unless a test says otherwise.
const returnProps = {
    returnable: {
        eligible: false,
        refusal: 'order_not_returnable',
        window_closes_at: null,
        lines: [],
    },
    returns: [],
    reasons: ['damaged', 'wrong_item'],
};

describe('one website order', function () {
    it('shows what was bought, where it goes and what happened', () => {
        render(
            <WebsiteOrder
                website={website}
                order={order}
                {...returnProps}
                can={{ cancel: false, request_return: false }}
            />,
        );

        expect(
            screen.getByText('FW-1043-NVY-M · Navy / M'),
        ).toBeInTheDocument();
        expect(screen.getByText('Ayesha Rahman')).toBeInTheDocument();
        expect(
            screen.getByText(/House 12, Road 5, Dhaka, Dhaka, 1216, BD/),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/৳5,?260\.00/).length).toBeGreaterThan(0);
        expect(
            screen.getByText(
                'Order placed on the website. Waiting for the customer’s payment.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('website.orders.stock:', { exact: false }),
        ).toBeInTheDocument();
        // Statuses read in the reader's language, not the server's English.
        expect(
            screen.getAllByText('orders.statuses.payment_pending').length,
        ).toBeGreaterThan(0);
    });

    it('shows where a cash-on-delivery confirmation stands, and never a code', () => {
        const { container } = render(
            <WebsiteOrder
                website={website}
                order={{
                    ...order,
                    status: 'customer_verification_pending',
                    payment_method: 'cod',
                    payment: { ...order.payment!, method: 'cod' },
                    confirmation: {
                        state: 'pending',
                        expires_at: '2026-09-21T10:00:00+06:00',
                        code_outstanding: true,
                        resend_available_in: 42,
                    },
                }}
                {...returnProps}
                can={{ cancel: true, request_return: false }}
            />,
        );

        expect(
            screen.getByText('website.orders.confirmation.title'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('website.orders.confirmation.code_sent'),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('website.orders.payment_methods.cod').length,
        ).toBeGreaterThan(0);

        // Nothing that could be a code reaches the page. Six digits standing
        // on their own: the order reference carries a six-digit date.
        expect(container.textContent).not.toMatch(/(?<![\d-])\d{6}(?![\d-])/);
    });

    it('offers cancellation only when it is allowed, and asks first', async () => {
        const { unmount } = render(
            <WebsiteOrder
                website={website}
                order={order}
                {...returnProps}
                can={{ cancel: false, request_return: false }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'website.orders.cancel' }),
        ).not.toBeInTheDocument();

        unmount();
        render(
            <WebsiteOrder
                website={website}
                order={order}
                {...returnProps}
                can={{ cancel: true, request_return: false }}
            />,
        );

        const ask = screen.getByRole('button', {
            name: 'website.orders.cancel',
        });

        // Nothing is submitted until it is confirmed.
        expect(
            screen.queryByRole('button', {
                name: 'website.orders.cancel_confirm',
            }),
        ).not.toBeInTheDocument();

        ask.click();

        expect(
            await screen.findByRole('button', {
                name: 'website.orders.cancel_confirm',
            }),
        ).toBeInTheDocument();
    });

    it('shows each return, its refund, and offers a return only when the server allows it', async () => {
        const { unmount } = render(
            <WebsiteOrder
                website={website}
                order={order}
                returnable={{
                    eligible: true,
                    refusal: null,
                    window_closes_at: '2026-09-27T10:00:00+06:00',
                    lines: [
                        {
                            id: '01LINE',
                            sku: 'FW-1043-NVY-M',
                            name: 'Cotton panjabi',
                            variant: 'Navy / M',
                            sold: 2,
                            returned: 1,
                            returnable: 1,
                        },
                    ],
                }}
                returns={[
                    {
                        id: '01RETURN',
                        reference: 'RET-260920-ABCD1234',
                        status: 'received',
                        reason: 'damaged',
                        customer_note: null,
                        requested_at: '2026-09-20T12:00:00+06:00',
                        decision_note: 'The stitching is undone.',
                        lines: [
                            {
                                id: '01RLINE',
                                sku: 'FW-1043-NVY-M',
                                name: 'Cotton panjabi',
                                quantity: 1,
                                approved_quantity: 1,
                                received_quantity: 1,
                            },
                        ],
                        refund: {
                            state: 'manual_review',
                            amount: money(260000),
                            refunded_at: null,
                        },
                        timeline: [
                            {
                                status: 'requested',
                                at: '2026-09-20T12:00:00+06:00',
                                note: 'Return requested.',
                            },
                        ],
                        can_cancel: false,
                    },
                ]}
                reasons={['damaged', 'wrong_item']}
                can={{ cancel: false, request_return: true }}
            />,
        );

        expect(screen.getByText('RET-260920-ABCD1234')).toBeInTheDocument();
        expect(
            screen.getByText('returns.refund_states.manual_review'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The stitching is undone.'),
        ).toBeInTheDocument();

        screen
            .getByRole('button', { name: 'website.orders.returns.ask' })
            .click();

        // The quantity offered is the server's figure, never more.
        const quantity = await screen.findByRole('spinbutton');

        expect(quantity).toHaveAttribute('max', '1');

        unmount();
        render(
            <WebsiteOrder
                website={website}
                order={order}
                {...returnProps}
                can={{ cancel: false, request_return: false }}
            />,
        );

        expect(
            screen.queryByRole('button', {
                name: 'website.orders.returns.ask',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'website.orders.returns.refusals.order_not_returnable',
            ),
        ).toBeInTheDocument();
    });
});
