// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { OrderRevisionPanel } from './editable-order-details';

const submit = vi.fn();
let transformed: Record<string, unknown> = {};

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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: EditableOrderDetails } =
    await import('./editable-order-details');

function panel(
    overrides: Partial<OrderRevisionPanel> = {},
): OrderRevisionPanel {
    return {
        number: 2,
        blocker: null,
        blocker_message: null,
        can: { edit_customer: true, edit_address: true },
        form: {
            customer: { name: 'Ayesha Rahman', mobile: '01711112222' },
            shipping_address: { line1: 'House 4, Road 2', city: 'Dhaka' },
            billing_address: null,
            customer_note: null,
        },
        history: [],
        ...overrides,
    };
}

describe("editing an order's details in place", () => {
    it('puts a pencil beside every value the person may change', () => {
        render(<EditableOrderDetails orderId="01ORDER" revision={panel()} />);

        expect(
            screen.getAllByRole('button', {
                name: 'orders.revision.edit_field',
            }),
        ).toHaveLength(5);
        expect(screen.getByText('House 4, Road 2')).toBeTruthy();
    });

    it('turns one value into an input right where it is, and sends only that field with its reason', () => {
        render(<EditableOrderDetails orderId="01ORDER" revision={panel()} />);

        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'orders.revision.edit_field',
            })[2],
        );

        const input = screen.getByDisplayValue('House 4, Road 2');
        fireEvent.change(input, { target: { value: 'House 12, Road 4' } });
        fireEvent.change(screen.getByLabelText('orders.revision.reason'), {
            target: { value: 'Customer corrected the house number.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'orders.revision.save' }),
        );

        expect(submit).toHaveBeenCalledTimes(1);
        expect(transformed).toMatchObject({
            shipping_address: { line1: 'House 12, Road 4' },
            reason: 'Customer corrected the house number.',
            base_revision: 2,
        });
        expect(transformed).not.toHaveProperty('customer');
    });

    it('keeps save disabled until a reason is given', () => {
        render(<EditableOrderDetails orderId="01ORDER" revision={panel()} />);

        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'orders.revision.edit_field',
            })[0],
        );

        expect(
            (
                screen.getByRole('button', {
                    name: 'orders.revision.save',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('shows no pencil when the order may no longer be edited, or the person may not change that part', () => {
        const { rerender } = render(
            <EditableOrderDetails
                orderId="01ORDER"
                revision={panel({
                    blocker: 'courier_booked',
                    blocker_message: 'A courier holds it.',
                })}
            />,
        );

        expect(
            screen.queryAllByRole('button', {
                name: 'orders.revision.edit_field',
            }),
        ).toHaveLength(0);

        rerender(
            <EditableOrderDetails
                orderId="01ORDER"
                revision={panel({
                    can: { edit_customer: false, edit_address: true },
                })}
            />,
        );

        // Only the two address fields.
        expect(
            screen.getAllByRole('button', {
                name: 'orders.revision.edit_field',
            }),
        ).toHaveLength(2);
    });
});
