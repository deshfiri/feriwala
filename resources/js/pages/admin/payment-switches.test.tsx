// @vitest-environment jsdom

import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    GatewaySwitchRow,
    PaymentSwitchSummary,
} from './payment-switches';

const { router } = vi.hoisted(() => ({ router: { put: vi.fn() } }));

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
        router,
        usePage: () => ({ props: { translations: {}, permissions: {} } }),
    };
});

const { default: PaymentSwitches } = await import('./payment-switches');

function paymentSwitchRow(
    overrides: Partial<GatewaySwitchRow> & { name: string },
): GatewaySwitchRow {
    return {
        label: overrides.name,
        is_implemented: true,
        is_operational: true,
        is_enabled: false,
        is_configured: true,
        is_available: false,
        is_sandbox: true,
        missing_configuration: [],
        received: [{ amount: '0.00', currency: 'BDT', formatted: '৳0.00' }],
        payments: 0,
        last_paid_at: null,
        ...overrides,
    };
}

const gateways: GatewaySwitchRow[] = [
    paymentSwitchRow({ name: 'sslcommerz', is_enabled: true }),
    paymentSwitchRow({ name: 'bkash' }),
    paymentSwitchRow({
        name: 'stripe',
        is_configured: false,
        missing_configuration: ['secret_key'],
    }),
    paymentSwitchRow({
        name: 'nagad',
        is_implemented: true,
        is_operational: false,
        is_configured: false,
    }),
    paymentSwitchRow({
        name: 'rocket',
        is_implemented: false,
        is_operational: false,
        is_configured: false,
    }),
];

const paymentSwitchSummary: PaymentSwitchSummary = {
    received: [{ amount: '0.00', currency: 'BDT', formatted: '৳0.00' }],
    payments: 0,
    taking_payments: 1,
};

function paymentSwitchFor(name: string): HTMLElement {
    const row = document.querySelector<HTMLElement>(
        `[data-test="gateway-row-${name}"]`,
    );

    if (!row) {
        throw new Error(`No row for ${name}`);
    }

    return within(row).getByRole('switch');
}

/**
 * Every gateway gets a row and a switch. The switch mirrors what the server
 * will accept — on only with credentials and a working driver, off always —
 * and posts to the existing toggle action rather than deciding anything.
 */
describe('the payments switch list', () => {
    beforeEach(() => router.put.mockReset());

    it('shows one switch per gateway, reflecting whether it is on', () => {
        render(
            <PaymentSwitches
                gateways={gateways}
                summary={paymentSwitchSummary}
                can={{ manage: true }}
            />,
        );

        expect(screen.getAllByRole('switch')).toHaveLength(5);
        expect(paymentSwitchFor('sslcommerz')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(paymentSwitchFor('bkash')).toHaveAttribute(
            'aria-checked',
            'false',
        );
    });

    it('locks the switch-on of gateways the server would refuse, and says why', () => {
        render(
            <PaymentSwitches
                gateways={gateways}
                summary={paymentSwitchSummary}
                can={{ manage: true }}
            />,
        );

        expect(paymentSwitchFor('sslcommerz')).toBeEnabled();
        expect(paymentSwitchFor('bkash')).toBeEnabled();
        expect(paymentSwitchFor('stripe')).toBeDisabled();
        expect(paymentSwitchFor('nagad')).toBeDisabled();
        expect(paymentSwitchFor('rocket')).toBeDisabled();

        expect(
            screen.getByText('gateways.switches.reason.not_configured'),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('gateways.switches.reason.not_implemented'),
        ).toHaveLength(2);
    });

    it('still lets a gateway that is on be switched off even without credentials', () => {
        render(
            <PaymentSwitches
                gateways={[
                    paymentSwitchRow({
                        name: 'stripe',
                        is_enabled: true,
                        is_configured: false,
                    }),
                ]}
                summary={paymentSwitchSummary}
                can={{ manage: true }}
            />,
        );

        expect(paymentSwitchFor('stripe')).toBeEnabled();
    });

    it('sends the flipped state for that gateway to the toggle action', () => {
        render(
            <PaymentSwitches
                gateways={gateways}
                summary={paymentSwitchSummary}
                can={{ manage: true }}
            />,
        );

        fireEvent.click(paymentSwitchFor('bkash'));
        fireEvent.click(paymentSwitchFor('sslcommerz'));

        expect(router.put).toHaveBeenCalledTimes(2);
        expect(router.put.mock.calls[0][0]).toBe('/admin/gateways/enabled');
        expect(router.put.mock.calls[0][1]).toEqual({
            gateway: 'bkash',
            enabled: true,
        });
        expect(router.put.mock.calls[1][1]).toEqual({
            gateway: 'sslcommerz',
            enabled: false,
        });
    });

    it("shows each gateway's own takings, and the total, exactly as the server sent them", () => {
        render(
            <PaymentSwitches
                gateways={[
                    paymentSwitchRow({
                        name: 'sslcommerz',
                        is_enabled: true,
                        received: [
                            {
                                amount: '1250.75',
                                currency: 'BDT',
                                formatted: '৳1,250.75',
                            },
                        ],
                        payments: 2,
                        last_paid_at: '29 Sep 2026, 3:10 PM',
                    }),
                    paymentSwitchRow({
                        name: 'stripe',
                        received: [
                            {
                                amount: '2000.00',
                                currency: 'BDT',
                                formatted: '৳2,000.00',
                            },
                            {
                                amount: '100.00',
                                currency: 'USD',
                                formatted: '$100.00',
                            },
                        ],
                        payments: 2,
                    }),
                ]}
                summary={{
                    received: [
                        {
                            amount: '3250.75',
                            currency: 'BDT',
                            formatted: '৳3,250.75',
                        },
                        {
                            amount: '100.00',
                            currency: 'USD',
                            formatted: '$100.00',
                        },
                    ],
                    payments: 4,
                    taking_payments: 1,
                }}
                can={{ manage: true }}
            />,
        );

        const received = (name: string) =>
            document.querySelector<HTMLElement>(
                `[data-test="gateway-received-${name}"]`,
            )!;

        expect(
            within(received('sslcommerz')).getByText('৳1,250.75'),
        ).toBeInTheDocument();
        expect(
            within(received('stripe')).getByText('৳2,000.00'),
        ).toBeInTheDocument();
        expect(
            within(received('stripe')).getByText('$100.00'),
        ).toBeInTheDocument();
        expect(screen.getByText('৳3,250.75')).toBeInTheDocument();
        expect(screen.getByText('4')).toBeInTheDocument();
    });

    it('is read-only for someone who may not manage gateways', () => {
        render(
            <PaymentSwitches
                gateways={gateways}
                summary={paymentSwitchSummary}
                can={{ manage: false }}
            />,
        );

        screen
            .getAllByRole('switch')
            .forEach((toggle) => expect(toggle).toBeDisabled());
        expect(
            screen.getByText('gateways.switches.read_only'),
        ).toBeInTheDocument();
    });
});
