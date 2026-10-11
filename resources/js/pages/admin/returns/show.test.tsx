// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

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
        // Forms render their fields, so what each step offers can be seen.
        Form: ({
            children,
        }: {
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                {},
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: AdminReturn } = await import('./show');

type Props = ComponentProps<typeof AdminReturn>;

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const none = {
    approve: false,
    reject: false,
    receive: false,
    start_refund: false,
    decide_refund: false,
    send_refund: false,
    settle_manually: false,
};

function props(overrides: Partial<Props> = {}): Props {
    return {
        orderReturn: {
            id: '01RETURN',
            reference: 'RET-260920-ABCD1234',
            status: 'requested',
            status_tone: 'warning',
            reason: 'damaged',
            customer_note: 'The stitching is undone.',
            evidence: [],
            decision_note: null,
            source: 'storefront',
            requested_at: '2026-09-20T12:00:00+06:00',
            decided_at: null,
            received_at: null,
            order: { id: '01ORDER', reference: 'ORD-260915-TEST0001' },
            account: 'Karim Traders',
            website: 'Ayesha Fashion',
            cash_on_delivery: true,
            lines: [
                {
                    id: '01RLINE',
                    sku: 'FW-1043-NVY-M',
                    name: 'Cotton panjabi',
                    variant: null,
                    sold: 2,
                    quantity: 1,
                    approved_quantity: null,
                    received_quantity: 0,
                    disposition: null,
                    warehouse: null,
                    restored: false,
                    unit_price: money('2600.00'),
                    refund_amount: null,
                },
            ],
            refund: {
                state: 'not_required',
                tone: 'neutral',
                amount: null,
                note: null,
                refunded_at: null,
                request: null,
            },
            history: [
                {
                    previous_status: null,
                    new_status: 'requested',
                    at: '2026-09-20T12:00:00+06:00',
                    source: 'storefront',
                    actor: null,
                    reason: 'Return asked for: Arrived damaged.',
                    internal_note: null,
                    public_note: 'Return requested.',
                },
            ],
        },
        warehouses: [{ id: '01WAREHOUSE', label: 'DHK · Dhaka' }],
        dispositions: ['restock', 'damaged', 'quarantine'],
        can: none,
        ...overrides,
    };
}

/**
 * The staff view of a return (§18.2, P6-12): every step is offered only when
 * the server says the return is at it and the person may take it.
 */
describe('admin return page', () => {
    it('offers no step the server has not allowed', () => {
        render(<AdminReturn {...props()} />);

        expect(
            screen.queryByRole('button', { name: 'returns.admin.approve' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'returns.admin.receive' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'returns.admin.settle' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('The stitching is undone.'),
        ).toBeInTheDocument();
    });

    it('asks for a reason with a decision, and a disposition for every line received', () => {
        const { unmount } = render(
            <AdminReturn
                {...props({ can: { ...none, approve: true, reject: true } })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'returns.admin.approve' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'returns.admin.reject' }),
        ).toBeInTheDocument();

        // A reason is chosen from the ready-made list (or written under Other).
        for (const reason of screen.getAllByRole('combobox')) {
            expect(reason).toBeRequired();
        }

        unmount();
        render(<AdminReturn {...props({ can: { ...none, receive: true } })} />);

        const disposition = screen.getByRole('combobox', {
            name: /returns.admin.fields.disposition/,
        });

        expect(disposition).toBeRequired();
        expect(
            screen.getByRole('option', {
                name: 'returns.dispositions.restock',
            }),
        ).toBeInTheDocument();
    });

    it('says a cash-on-delivery refund is settled by hand, and offers only that', () => {
        const base = props();

        render(
            <AdminReturn
                {...props({
                    orderReturn: {
                        ...base.orderReturn,
                        status: 'received',
                        refund: {
                            state: 'manual_review',
                            tone: 'warning',
                            amount: money('2600.00'),
                            note: 'Paid on delivery: the money never came through a gateway.',
                            refunded_at: null,
                            request: null,
                        },
                    },
                    can: { ...none, settle_manually: true },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'Paid on delivery',
        );
        expect(
            screen.getByRole('button', { name: 'returns.admin.settle' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'returns.admin.send_refund' }),
        ).not.toBeInTheDocument();
    });
});
