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
        method: 'Online payment',
        expires_at: '2026-09-20T10:14:00+06:00',
        completed_at: null,
    },
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

describe('one website order', function () {
    it('shows what was bought, where it goes and what happened', () => {
        render(
            <WebsiteOrder
                website={website}
                order={order}
                can={{ cancel: false }}
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
    });

    it('offers cancellation only when it is allowed, and asks first', async () => {
        const { unmount } = render(
            <WebsiteOrder
                website={website}
                order={order}
                can={{ cancel: false }}
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
                can={{ cancel: true }}
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
});
