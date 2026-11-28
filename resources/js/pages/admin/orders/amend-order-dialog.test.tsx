// @vitest-environment jsdom

import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OrderAmendPanel } from './amend-order-dialog';
import type { OrderRevisionPanel } from './editable-order-details';

const submit = vi.fn();
const previewSubmit = vi.fn();
let transformed: Record<string, unknown> = {};
let previewBody: Record<string, unknown> = {};

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState<T>(initial);

            return {
                data,
                setData: (key: keyof T | T, value?: unknown) =>
                    typeof key === 'object'
                        ? setState(key)
                        : setState((previous) => ({
                              ...previous,
                              [key]: value,
                          })),
                transform: (callback: (data: T) => Record<string, unknown>) => {
                    transformed = callback(data);
                },
                submit,
                clearErrors: vi.fn(),
                processing: false,
                errors: {},
            };
        },
        useHttp: () => ({
            transform: (callback: () => Record<string, unknown>) => {
                previewBody = callback();
            },
            submit: (...args: unknown[]) => {
                previewSubmit(...args);

                return Promise.resolve({
                    ok: true,
                    totals: {
                        subtotal: money('7800.00'),
                        discount: money('0.00'),
                        delivery: money('0.00'),
                        tax: money('0.00'),
                        total: money('7800.00'),
                        total_before: money('5200.00'),
                        difference: money('2600.00'),
                    },
                    payment_consequence: 'cod_amount_updated',
                    payment_consequence_label: 'Amount to collect updated',
                    stock: [{ sku: 'FW-1', before: 2, after: 3 }],
                    profit: {
                        net_selling: money('7800.00'),
                        product_base_price: money('4500.00'),
                        delivery_charge: money('0.00'),
                        partner_profit: money('3300.00'),
                        banij_receivable: money('4500.00'),
                        unproven: false,
                        negative: false,
                    },
                });
            },
        }),
        usePage: () => ({ props: { translations: {} } }),
    };
});

vi.mock('@/hooks/use-confirm', () => ({
    useConfirm: () => () => Promise.resolve(true),
}));

function money(amount: string) {
    return { amount, currency: 'BDT', formatted: `৳${amount}` };
}

const { default: AmendOrderDialog } = await import('./amend-order-dialog');

function revision(): OrderRevisionPanel {
    return {
        number: 0,
        blocker: null,
        blocker_message: null,
        can: {
            edit_customer: true,
            edit_address: true,
            revise_items: true,
            revise_financials: true,
        },
        form: {
            customer: { name: 'Ayesha Rahman' },
            shipping_address: { line1: 'House 4' },
            billing_address: null,
            customer_note: null,
        },
        history: [],
    };
}

function amend(): OrderAmendPanel {
    return {
        reference: 'ORD-1',
        lines_editable: true,
        lines: [
            {
                id: 'LINE1',
                sku: 'FW-1',
                name: 'Cotton panjabi',
                variant: null,
                quantity: 2,
                unit_price: '2600.00',
                line_total: money('5200.00'),
            },
        ],
        discount: '0.00',
        delivery: '0.00',
        totals: {
            subtotal: money('5200.00'),
            discount: money('0.00'),
            delivery: money('0.00'),
            tax: money('0.00'),
            total: money('5200.00'),
        },
        payment: { status: 'Draft', method: 'cod', amount: money('5200.00') },
        fulfilment: { courier: 'Unassigned', delivery: 'Not shipped' },
        products: [{ sku: 'FW-2', name: 'Silk scarf', price: '3000.00' }],
    };
}

describe('the Order Details editor', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        submit.mockClear();
        previewSubmit.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('opens on six tabs and offers no status control', () => {
        render(
            <AmendOrderDialog
                orderId="01ORDER"
                revision={revision()}
                amend={amend()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'orders.amend.open' }),
        );

        expect(
            screen.getAllByRole('tab').map((tab) => tab.textContent),
        ).toEqual([
            'orders.amend.tabs.customer',
            'orders.amend.tabs.address',
            'orders.amend.tabs.items',
            'orders.amend.tabs.pricing',
            'orders.amend.tabs.payment',
            'orders.amend.tabs.fulfilment',
        ]);
        expect(screen.queryByLabelText(/status/i)).toBeNull();
    });

    it('asks the server for the totals and profit, and sends only what changed with the reason', async () => {
        render(
            <AmendOrderDialog
                orderId="01ORDER"
                revision={revision()}
                amend={amend()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'orders.amend.open' }),
        );
        fireEvent.click(
            screen.getByRole('tab', { name: 'orders.amend.tabs.items' }),
        );
        fireEvent.change(
            screen.getByLabelText('orders.amend.fields.quantity'),
            { target: { value: '3' } },
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(500);
        });

        expect(previewSubmit).toHaveBeenCalledTimes(1);
        expect(previewBody).toMatchObject({
            lines: [{ id: 'LINE1', quantity: 3, unit_price: null }],
        });
        expect(screen.getAllByText('৳3300.00').length).toBeGreaterThan(0);
        expect(
            screen.getByText('orders.amend.profit.delivery_charge'),
        ).toBeInTheDocument();

        const save = screen.getByRole('button', {
            name: 'orders.amend.save',
        }) as HTMLButtonElement;
        expect(save.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('orders.amend.reason'), {
            target: { value: 'Customer wants one more.' },
        });
        fireEvent.click(save);
        fireEvent.click(save);

        expect(submit).toHaveBeenCalledTimes(1);
        expect(transformed).toMatchObject({
            reason: 'Customer wants one more.',
            base_revision: 0,
            lines: [{ id: 'LINE1', quantity: 3 }],
        });
        expect(transformed).not.toHaveProperty('customer');
        expect(transformed).not.toHaveProperty('discount');
    });

    it('asks for the password only when a price changes', () => {
        render(
            <AmendOrderDialog
                orderId="01ORDER"
                revision={revision()}
                amend={amend()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'orders.amend.open' }),
        );

        expect(screen.queryByLabelText('orders.amend.password')).toBeNull();

        fireEvent.click(
            screen.getByRole('tab', { name: 'orders.amend.tabs.pricing' }),
        );
        fireEvent.change(
            screen.getByLabelText('orders.amend.fields.discount'),
            { target: { value: '100.00' } },
        );

        expect(screen.getByLabelText('orders.amend.password')).toBeTruthy();
    });
});
