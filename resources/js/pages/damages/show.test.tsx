// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** What the server answered to the last submit: set by a test, read by the form. */
let serverErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        router: { on: vi.fn(() => () => {}) },
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
            children,
            action,
        }: {
            action?: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, 'aria-label': action },
                children({ errors: serverErrors, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: MyDamage } = await import('./show');

type Props = ComponentProps<typeof MyDamage>;

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

function props(
    overrides: Partial<Props> = {},
    damage: Partial<Props['damage']> = {},
): Props {
    return {
        damage: {
            id: '01DMG',
            reference: 'DMG-261021-BBBB2222',
            order_number: 'ORD-261015-TEST0001',
            product: {
                sku: 'FW-1043-NVY-M',
                name: 'Cotton panjabi',
                variation: 'Navy / M',
            },
            image: null,
            quantity: 2,
            cause: 'partner_handling',
            cause_label: 'Handling by the Partner',
            approved_loss: money('5000.00'),
            responsibility: 'partner',
            responsibility_label: 'Partner',
            storage_state: 'held_by_banij',
            delivery_state: 'available',
            status: 'finalized',
            status_label: 'Finalized',
            updated_at: '2026-10-22T09:00:00+06:00',
            condition_label: 'Torn',
            description: null,
            order_url: '/store-orders/01ORDER',
            remains_with_customer: false,
            dispositions: [],
            delivery_request: {
                state: 'available',
                delivery_charge: null,
                tracking_number: null,
                requested_at: null,
                dispatched_at: null,
                received_at: null,
            },
            tracking: null,
            reported_at: '2026-10-21T09:00:00+06:00',
            ...damage,
        },
        evidence: [],
        legacy_evidence: [],
        financials: {
            summary: [],
            ledger: [],
            totals: {
                deducted: money('5000.00'),
                outstanding: money('0.00'),
                reversed: money('0.00'),
                net_partner: money('5000.00'),
                banij_cost: null,
            },
        },
        timeline: [],
        delivery: {
            can_request: true,
            quote: {
                charge: money('80.00'),
                quantity: 2,
                wallet_can_cover: true,
            },
            addresses: [
                {
                    id: '01ADDR',
                    label: 'Karim · House 12, Dhaka',
                    is_default: true,
                },
                {
                    id: '01ADDR2',
                    label: 'Karim · Shop 4, Chattogram',
                    is_default: false,
                },
            ],
        },
        can_confirm_receipt: false,
        ...overrides,
    };
}

describe('one damaged item', () => {
    beforeEach(() => {
        serverErrors = {};
    });

    it('shows the cost to the Partner and where the item is, never as sellable', () => {
        render(<MyDamage {...props()} />);

        expect(
            screen.getByText('damages.storage_states.held_by_banij'),
        ).toBeVisible();
        expect(
            screen.getByText('damages.delivery_states.available'),
        ).toBeVisible();
        expect(document.body.textContent).not.toMatch(
            /sellable|restock|on sale/i,
        );
    });

    it('explains an item that stays with the customer, and offers nothing to request', () => {
        render(
            <MyDamage
                {...props(
                    {
                        delivery: {
                            can_request: false,
                            quote: null,
                            addresses: [],
                        },
                    },
                    {
                        remains_with_customer: true,
                        delivery_request: {
                            state: 'not_available',
                            delivery_charge: null,
                            tracking_number: null,
                            requested_at: null,
                            dispatched_at: null,
                            received_at: null,
                        },
                    },
                )}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'damages.remains_with_customer',
        );
        expect(
            screen.queryByRole('button', { name: 'damages.request_delivery' }),
        ).toBeNull();
    });
});

describe('asking for the item to be delivered', () => {
    beforeEach(() => {
        serverErrors = {};
    });

    const open = async () => {
        const user = userEvent.setup();
        render(<MyDamage {...props()} />);
        await user.click(
            screen.getByRole('button', { name: 'damages.request_delivery' }),
        );

        return { user, dialog: screen.getByRole('dialog') };
    };

    it("shows the server's charge, only the Partner's own addresses, and sends no amount", async () => {
        const { dialog } = await open();

        const quote = within(dialog).getByRole('region', {
            name: 'damages.delivery_charge',
        });
        expect(quote).toHaveTextContent('৳80.00');
        expect(quote).toHaveTextContent('damages.charge_is_the_servers');

        const address = within(dialog).getByRole('combobox', {
            name: /damages\.deliver_to/,
        });
        expect(within(address).getAllByRole('option')).toHaveLength(2);
        expect(address).toHaveValue('01ADDR');

        const names = Array.from(
            dialog.querySelectorAll('input, select, textarea'),
        ).map((field) => field.getAttribute('name') ?? '');
        expect(names.join(' ')).not.toMatch(/charge|amount|total|price/i);
        expect(within(dialog).getByRole('checkbox')).toBeRequired();
    });

    it('warns, before anything is sent, that the wallet cannot cover the charge', async () => {
        const user = userEvent.setup();
        render(
            <MyDamage
                {...props({
                    delivery: {
                        ...props().delivery,
                        quote: {
                            charge: money('80.00'),
                            quantity: 2,
                            wallet_can_cover: false,
                        },
                    },
                })}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'damages.request_delivery' }),
        );

        expect(
            within(screen.getByRole('dialog')).getByRole('alert'),
        ).toHaveTextContent('damages.wallet_too_low');
    });

    it("shows the server's refusal on the field it concerns — insufficient balance, an address that is not theirs", async () => {
        serverErrors = {
            address:
                'Your wallet balance is not enough to pay for the delivery.',
        };
        const { dialog } = await open();

        expect(
            within(dialog).getByText(
                'Your wallet balance is not enough to pay for the delivery.',
            ),
        ).toBeVisible();
    });

    it('asks for an address first when there is none, and cannot be submitted without one', async () => {
        const user = userEvent.setup();
        render(
            <MyDamage
                {...props({ delivery: { ...props().delivery, addresses: [] } })}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'damages.request_delivery' }),
        );

        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'damages.no_address',
        );
        expect(
            within(dialog).getByRole('link', { name: 'damages.add_address' }),
        ).toBeVisible();
        expect(
            within(dialog).getByRole('button', {
                name: 'damages.pay_and_request',
            }),
        ).toBeDisabled();
    });
});

describe('after delivery has been requested', () => {
    it('offers no second request, shows the delivery and its tracking, and lets the Partner confirm receipt', () => {
        render(
            <MyDamage
                {...props(
                    {
                        delivery: {
                            can_request: false,
                            quote: null,
                            addresses: [],
                        },
                        can_confirm_receipt: true,
                    },
                    {
                        storage_state: 'in_transit',
                        status: 'dispatched',
                        delivery_request: {
                            state: 'dispatched',
                            delivery_charge: money('80.00'),
                            tracking_number: 'TRK-1001',
                            requested_at: '2026-10-23T09:00:00+06:00',
                            dispatched_at: '2026-10-24T09:00:00+06:00',
                            received_at: null,
                        },
                        tracking: { number: 'TRK-1001', courier: 'Pathao' },
                    },
                )}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'damages.request_delivery' }),
        ).toBeNull();
        expect(screen.getByText('TRK-1001 (Pathao)')).toBeVisible();
        expect(
            screen.getByText('damages.storage_states.in_transit'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'damages.confirm_received' }),
        ).toBeEnabled();
    });
});
