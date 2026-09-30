// @vitest-environment jsdom

import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GatewaySwitchRow } from './payment-switches';

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
        render(<PaymentSwitches gateways={gateways} can={{ manage: true }} />);

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
        render(<PaymentSwitches gateways={gateways} can={{ manage: true }} />);

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
                can={{ manage: true }}
            />,
        );

        expect(paymentSwitchFor('stripe')).toBeEnabled();
    });

    it('sends the flipped state for that gateway to the toggle action', () => {
        render(<PaymentSwitches gateways={gateways} can={{ manage: true }} />);

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

    it('is read-only for someone who may not manage gateways', () => {
        render(<PaymentSwitches gateways={gateways} can={{ manage: false }} />);

        screen
            .getAllByRole('switch')
            .forEach((toggle) => expect(toggle).toBeDisabled());
        expect(
            screen.getByText('gateways.switches.read_only'),
        ).toBeInTheDocument();
    });
});
