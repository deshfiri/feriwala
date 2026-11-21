// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DeliveryRate } from './index';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Form: ({ children }: { children: (s: unknown) => unknown }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        router: { delete: vi.fn() },
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: DeliveryChargesIndex } = await import('./index');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const rule = (amount: string) => ({
    id: `01${amount}`,
    amount: money(amount),
    is_free: amount === '0.00',
    since: '2026-11-01T10:00:00+06:00',
});

const rates: DeliveryRate[] = [
    {
        key: 'inside_dhaka',
        group: 'customer',
        rule: rule('80.00'),
        preview: {
            subtotal: money('1000.00'),
            delivery: money('80.00'),
            is_free: false,
            delivery_tax: null,
            total: money('1080.00'),
        },
    },
    { key: 'outside_dhaka', group: 'customer', rule: null, preview: null },
    { key: 'wholesale', group: 'wholesale', rule: rule('0.00'), preview: null },
];

const renderPage = (
    props: Partial<Parameters<typeof DeliveryChargesIndex>[0]> = {},
) =>
    render(
        <DeliveryChargesIndex
            rates={rates}
            customer_checkout_ready={false}
            can_manage
            {...props}
        />,
    );

describe('the delivery charges screen', () => {
    it('explains the rule, Dhaka district not division, before anything else', () => {
        renderPage();

        expect(
            screen.getByText('delivery_charges.guide.dhaka'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('delivery_charges.guide.per_order'),
        ).toBeInTheDocument();
    });

    it('shows each rate, the server preview, a missing rate and a free one', () => {
        renderPage();

        expect(
            screen.getByText('delivery_charges.rates.inside_dhaka.title'),
        ).toBeInTheDocument();
        expect(screen.getByText('৳1080.00')).toBeInTheDocument();
        expect(
            screen.getAllByText('delivery_charges.not_set').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByText('delivery_charges.free').length,
        ).toBeGreaterThan(0);
    });

    it('warns that customers cannot check out while a customer rate is missing', () => {
        const { rerender } = renderPage();

        expect(
            screen.getByText('delivery_charges.customer_not_ready'),
        ).toBeInTheDocument();

        rerender(
            <DeliveryChargesIndex
                rates={rates}
                customer_checkout_ready
                can_manage
            />,
        );

        expect(
            screen.queryByText('delivery_charges.customer_not_ready'),
        ).not.toBeInTheDocument();
    });

    it('offers no changes to someone who may only look', () => {
        renderPage({ can_manage: false });

        expect(
            screen.queryByRole('button', {
                name: 'delivery_charges.actions.change',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'delivery_charges.actions.deactivate',
            }),
        ).not.toBeInTheDocument();
    });
});
