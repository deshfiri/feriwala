// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Money } from '@/lib/money';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children?: unknown }) =>
            createElement('a', { href }, children as never),
        Form: ({
            action,
            method,
            children,
        }: {
            action: string;
            method: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, method, 'data-testid': `form-${action}` },
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: Checkout } = await import('./checkout');

const money = (amount: string): Money => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const page = (
    <Checkout
        package={{ slug: 'growth', name: 'Growth', validity_days: 365 }}
        quote={{
            lines: [
                {
                    type: 'package_fee',
                    label: 'Package fee',
                    amount: money('5000.00'),
                    is_deduction: false,
                },
            ],
            subtotal: money('5000.00'),
            total: money('5000.00'),
            currency: 'BDT',
            is_payable: true,
            tax: [],
            tax_included: money('0.00'),
        }}
        coupon={null}
        deadline={{
            hours: 24,
            expires_at: null,
            expired: false,
            reconciling: false,
        }}
        gateways={[{ name: 'sslcommerz', label: 'SSLCommerz' }]}
    />
);

/**
 * The checkout never sends the browser to the gateway itself (§26.4, D12).
 *
 * The payment is started by posting to Feriwala, which answers an Inertia
 * request with `X-Inertia-Location` so the client leaves the application with a
 * full page visit. A form that posted to the gateway — or a link that fetched
 * it — would be a cross-origin request the gateway refuses, which is the CORS
 * failure this page must never produce again.
 */
describe('the activation checkout', function () {
    it('submits the payment to Feriwala, not to the gateway', () => {
        const { container } = render(page);

        const form = screen.getByTestId('form-/checkout');

        expect(form.getAttribute('action')).toBe('/checkout');
        expect(form.getAttribute('method')).toBe('post');

        // Nothing on the page addresses the gateway's own site.
        expect(container.innerHTML).not.toMatch(/https?:\/\/[^"']*sslcommerz/i);

        for (const element of container.querySelectorAll(
            'a[href], form[action]',
        )) {
            const address =
                element.getAttribute('href') ??
                element.getAttribute('action') ??
                '';

            expect(
                address.startsWith('http://') || address.startsWith('https://'),
            ).toBe(false);
        }
    });

    it('offers the gateway as a choice, by name, rather than as an address', () => {
        render(page);

        const choice = screen.getByRole('radio', { name: /SSLCommerz/i });

        expect(choice).toHaveAttribute('value', 'sslcommerz');
    });
});
