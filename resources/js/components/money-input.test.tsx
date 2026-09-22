// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MoneyInput from './money-input';

/**
 * The one human-facing money input this application uses (§36.1).
 *
 * Submits the decimal string exactly as typed, prefixed with the currency
 * symbol for the person typing it — never a minor-unit integer, and never
 * something this component computes itself.
 */
describe('MoneyInput', () => {
    it('shows the Taka symbol and submits the raw decimal string under the given name', () => {
        render(
            <MoneyInput
                id="amount"
                name="amount"
                label="Amount"
                defaultValue="500.50"
            />,
        );

        const input = screen.getByLabelText('Amount') as HTMLInputElement;

        expect(screen.getByText('৳')).toBeInTheDocument();
        expect(input.name).toBe('amount');
        expect(input.type).toBe('text');
        expect(input.value).toBe('500.50');
    });

    it('accepts a custom currency symbol', () => {
        render(
            <MoneyInput
                id="amount-usd"
                name="amount"
                label="Amount"
                symbol="$"
            />,
        );

        expect(screen.getByText('$')).toBeInTheDocument();
        expect(screen.queryByText('৳')).not.toBeInTheDocument();
    });

    it('shows the field error and help text', () => {
        render(
            <MoneyInput
                id="amount-error"
                name="amount"
                label="Amount"
                error="The amount must be a valid decimal."
                helpText="Enter the amount in Taka."
            />,
        );

        expect(
            screen.getByText('The amount must be a valid decimal.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Enter the amount in Taka.'),
        ).toBeInTheDocument();
    });

    it('marks the field required and disabled when asked', () => {
        render(
            <MoneyInput
                id="amount-flags"
                name="amount"
                label="Amount"
                required
                disabled
            />,
        );

        const input = screen.getByLabelText('Amount') as HTMLInputElement;

        expect(input.required).toBe(true);
        expect(input.disabled).toBe(true);
    });
});
