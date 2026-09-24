// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { CheckoutSummary } from '@/types/wholesale';

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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WholesaleCheckout } = await import('./checkout');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const address = {
    contact_name: 'Karim Uddin',
    contact_mobile: '01712345678',
    line_1: 'House 12, Road 5',
    line_2: null,
    area: 'Mirpur',
    city: 'Dhaka',
    district: null,
    postcode: '1216',
    country: 'BD',
};

function checkout(overrides: Partial<CheckoutSummary> = {}): CheckoutSummary {
    return {
        fingerprint: 'f'.repeat(64),
        payment_methods: [{ name: 'sslcommerz', label: 'SSLCommerz' }],
        confirmation: {
            status: 'confirmed',
            payment_method: { name: 'sslcommerz', label: 'SSLCommerz' },
            confirmed_at: '2026-09-15T10:00:00+06:00',
            total: money('20000.00'),
        },
        lines: [
            {
                id: '01LINE',
                name: 'Electric kettle',
                sku: 'FW-KT',
                variant: null,
                quantity: 10,
                unit_price: money('2000.00'),
                line_total: money('20000.00'),
            },
        ],
        subtotal: money('20000.00'),
        coupon: null,
        discount: money('0.00'),
        delivery: money('0.00'),
        tax: [],
        tax_added: money('0.00'),
        total: money('20000.00'),
        addresses: { billing: address, shipping: address },
        ready_to_confirm: true,
        pending_order: null,
        resale_channels: ['own_website', 'marketplace', 'other'],
        ...overrides,
    } as CheckoutSummary;
}

/**
 * Paying for a confirmed wholesale checkout (P4-9, P4-14): the page sends the
 * summary's fingerprint and nothing priced, offers the optional resale channel,
 * and never offers a second way to pay while an order waits for its payment.
 */
describe('wholesale checkout payment', () => {
    it('offers to pay for the confirmed summary, sending its fingerprint and no figure', () => {
        const { container } = render(
            <WholesaleCheckout checkout={checkout()} />,
        );

        const pay = screen.getByRole('button', { name: /orders\.pay\.submit/ });
        const form = pay.closest('form') as HTMLFormElement;

        expect(form).toHaveAttribute(
            'action',
            expect.stringContaining('/wholesale/orders'),
        );
        expect(form.querySelector('input[name="fingerprint"]')).toHaveAttribute(
            'value',
            'f'.repeat(64),
        );

        const names = Array.from(form.querySelectorAll('[name]')).map((field) =>
            field.getAttribute('name'),
        );

        expect(names).toHaveLength(3);
        expect(names).toEqual(
            expect.arrayContaining([
                'customer_note',
                'fingerprint',
                'intended_resale_channel',
            ]),
        );
        expect(container.querySelector('[name="total"]')).toBeNull();
    });

    it('offers the resale channel as optional, defaulting to none', () => {
        render(<WholesaleCheckout checkout={checkout()} />);

        const select = screen.getByLabelText(
            'orders.resale.label',
        ) as HTMLSelectElement;

        expect(select.value).toBe('');
        expect(
            Array.from(select.options).map((option) => option.value),
        ).toEqual(['', 'own_website', 'marketplace', 'other']);
        expect(screen.getByText('orders.resale.help')).toBeInTheDocument();
    });

    it('points to the order waiting for payment instead of offering a second way to pay', () => {
        render(
            <WholesaleCheckout
                checkout={checkout({
                    pending_order: {
                        id: '01ORDER',
                        reference: 'ORD-260915-TEST0001',
                    },
                })}
            />,
        );

        expect(
            screen.getByText('orders.pay.pending_title'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'orders.pay.pending_link' }),
        ).toHaveAttribute('href', expect.stringContaining('01ORDER'));
        expect(
            screen.queryByRole('button', { name: /orders\.pay\.submit/ }),
        ).not.toBeInTheDocument();
    });

    it('offers no payment for a confirmation that no longer stands', () => {
        render(
            <WholesaleCheckout
                checkout={checkout({
                    confirmation: {
                        status: 'stale',
                        payment_method: {
                            name: 'sslcommerz',
                            label: 'SSLCommerz',
                        },
                        confirmed_at: '2026-09-15T10:00:00+06:00',
                        total: money('18000.00'),
                    },
                })}
            />,
        );

        expect(
            screen.getByText('wholesale.checkout.confirmation.stale'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /orders\.pay\.submit/ }),
        ).not.toBeInTheDocument();
    });

    it('offers no payment before the summary is confirmed', () => {
        render(
            <WholesaleCheckout checkout={checkout({ confirmation: null })} />,
        );

        expect(
            screen.queryByRole('button', { name: /orders\.pay\.submit/ }),
        ).not.toBeInTheDocument();
    });
});
