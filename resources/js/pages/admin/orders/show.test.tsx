// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { AdminOrderDetail } from './show';

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
        Form: () => null,
        useForm: () => ({
            data: { status: '', reason: '' },
            setData: vi.fn(),
            transform: vi.fn(),
            post: vi.fn(),
            reset: vi.fn(),
            processing: false,
            errors: {},
        }),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: AdminOrder } = await import('./show');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

function lifecycleAxis(status: string) {
    return {
        status,
        status_label: status,
        status_tone: 'info' as const,
        is_terminal: false,
        can_manage: false,
        available_actions: [],
        history: [],
    };
}

function order(overrides: Partial<AdminOrderDetail> = {}): AdminOrderDetail {
    return {
        id: '01ORDER',
        reference: 'ORD-260915-TEST0001',
        source: 'erp_wholesale',
        status: 'payment_pending',
        status_tone: 'warning',
        lifecycle: {
            fulfillment: lifecycleAxis('pending_review'),
            delivery: lifecycleAxis('not_shipped'),
            courier: lifecycleAxis('unassigned'),
        },
        account: 'Karim Traders',
        placed_by: 'Karim Uddin',
        website: null,
        storefront_reference: null,
        website_customer: null,
        placed_at: '2026-09-15T10:00:00+06:00',
        paid_at: null,
        cancelled_at: null,
        cancellation_reason: null,
        held_at: null,
        hold_reason: null,
        customer_note: null,
        intended_resale_channel: null,
        coupon_code: null,
        totals: { total: money('20000.00') },
        lines: [],
        payment: {
            reference: 'PAY-260915-TEST0001',
            status: 'initiated',
            method: 'online',
            gateway: 'sslcommerz',
            gateway_mode: 'sandbox',
            expires_at: '2026-09-15T10:14:00+06:00',
            completed_at: null,
            reconciliation_reason: null,
        },
        invoice: null,
        confirmation: null,
        history: [
            {
                previous_status: null,
                new_status: 'payment_pending',
                at: '2026-09-15T10:00:00+06:00',
                source: 'checkout',
                actor: 'Karim Uddin',
                reason: 'Placed from the wholesale checkout.',
                internal_note: null,
            },
        ],
        shipments: [],
        courier_providers: [],
        ...overrides,
    };
}

/**
 * The staff view of an order (§18.4): what the buyer's page leaves out — why it
 * is held, why a payment is under reconciliation, the staff note — and the one
 * action, only for those who may take it.
 */
describe('admin order page', () => {
    it('offers cancelling an unpaid order only when the server allows it', () => {
        const { rerender } = render(
            <AdminOrder
                order={order()}
                can={{ cancel: true, manage_shipments: false }}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'orders.admin.cancel' }),
        ).toBeInTheDocument();

        rerender(
            <AdminOrder
                order={order()}
                can={{ cancel: false, manage_shipments: false }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'orders.admin.cancel' }),
        ).not.toBeInTheDocument();
    });

    it('says why a paid order is held, and that resolving it is done elsewhere', () => {
        render(
            <AdminOrder
                order={order({
                    status: 'on_hold',
                    hold_reason:
                        "The payment settled, but the stock held for this order could not be committed. Line 1's reservation is already released.",
                    history: [
                        {
                            previous_status: 'payment_pending',
                            new_status: 'on_hold',
                            at: '2026-09-15T10:05:00+06:00',
                            source: 'payment_gateway',
                            actor: null,
                            reason: 'The payment settled, but the stock held for this order could not be committed.',
                            internal_note:
                                "Line 1's reservation is already released.",
                        },
                    ],
                })}
                can={{ cancel: false, manage_shipments: false }}
            />,
        );

        const notice = screen.getByRole('status');

        expect(notice).toHaveTextContent('orders.admin.sections.hold');
        expect(notice).toHaveTextContent('already released');
        expect(notice).toHaveTextContent('orders.admin.resolution_pending');
        const timeline = screen
            .getByText('orders.admin.history.internal')
            .closest('ol');

        expect(timeline).not.toBeNull();
        // A change nobody made by hand says so, beside where it came from.
        expect(timeline).toHaveTextContent('orders.admin.history.system');
        expect(timeline).toHaveTextContent(
            'orders.admin.change_sources.payment_gateway',
        );
    });

    it('says which shop a website order came through, and who bought it there', () => {
        render(
            <AdminOrder
                order={order({
                    source: 'website',
                    placed_by: null,
                    website: { id: '01WEBSITE', name: 'Ayesha Fashion' },
                    storefront_reference: 'SF-2026-000481',
                    website_customer: {
                        id: '01CUSTOMER',
                        mobile: '+8801712345678',
                        is_guest: true,
                    },
                })}
                can={{ cancel: false, manage_shipments: false }}
            />,
        );

        expect(screen.getByText('Ayesha Fashion')).toBeInTheDocument();
        expect(screen.getByText('SF-2026-000481')).toBeInTheDocument();
        expect(screen.getByText('+8801712345678')).toBeInTheDocument();
        expect(
            screen.getByText('orders.admin.fields.guest'),
        ).toBeInTheDocument();
    });

    it('says why a payment is under reconciliation', () => {
        render(
            <AdminOrder
                order={order({
                    status: 'cancelled',
                    payment: {
                        reference: 'PAY-260915-TEST0001',
                        status: 'reconciliation_required',
                        method: 'online',
                        gateway: 'sslcommerz',
                        gateway_mode: 'sandbox',
                        expires_at: '2026-09-15T10:14:00+06:00',
                        completed_at: null,
                        reconciliation_reason:
                            'The gateway confirmed this payment after the checkout was cancelled.',
                    },
                })}
                can={{ cancel: false, manage_shipments: false }}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.admin.sections.reconciliation',
        );
        expect(
            screen.getByText(
                'orders.admin.payment_statuses.reconciliation_required',
            ),
        ).toBeInTheDocument();
    });

    it('shows a cash-on-delivery confirmation, its failed attempts and no code', () => {
        const { container } = render(
            <AdminOrder
                order={order({
                    source: 'website',
                    status: 'customer_verification_pending',
                    payment: {
                        reference: 'PAY-260915-TEST0001',
                        status: 'draft',
                        method: 'cod',
                        gateway: null,
                        gateway_mode: null,
                        expires_at: '2026-09-16T10:00:00+06:00',
                        completed_at: null,
                        reconciliation_reason: null,
                    },
                    confirmation: {
                        state: 'pending',
                        expires_at: '2026-09-16T10:00:00+06:00',
                        code_outstanding: true,
                        resend_available_in: 0,
                        attempts_used: 2,
                        attempts_allowed: 5,
                        code_delivery: 'failed',
                        sends_used: 1,
                        sends_allowed: 5,
                    },
                })}
                can={{ cancel: true, manage_shipments: false }}
            />,
        );

        expect(
            screen.getByText('orders.admin.sections.confirmation'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('orders.admin.confirmation.attempts_value'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('orders.admin.payment_methods.cod'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('orders.admin.confirmation.resend_now'),
        ).toBeInTheDocument();
        // Whether the text went, said in words — never what it said.
        expect(
            screen.getByText(
                'orders.admin.confirmation.delivery_states.failed',
            ),
        ).toBeInTheDocument();

        // Staff see the shape of the code's life, never the code. Six digits
        // standing on their own: a reference carries a six-digit date.
        expect(container.textContent).not.toMatch(/(?<![\d-])\d{6}(?![\d-])/);
    });
});
