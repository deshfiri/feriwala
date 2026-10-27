// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { CaseAction, CaseItem, InspectionPanel } from '@/types/cases';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        router: { on: vi.fn(() => () => {}) },
        Form: ({
            children,
            action,
        }: {
            action: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, 'aria-label': 'step' },
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: CaseActionDialog } = await import('./case-action-dialog');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const item: CaseItem = {
    id: '01LINE',
    sku: 'FW-1043-NVY-M',
    name: 'Cotton panjabi',
    variant: 'Navy / M',
    image: null,
    sold: 3,
    requested: 2,
    approved: 2,
    received: null,
    disposition: null,
    disposition_label: null,
    restored: false,
    note: null,
    unit_price: money('2600.00'),
    supplier_backed: false,
    replaced_with: null,
};

const inspection: InspectionPanel = {
    recorded: false,
    outcome: null,
    note: null,
    inspected_at: null,
    dispositions: [
        { value: 'restock', label: 'Sellable' },
        { value: 'damaged', label: 'Damaged' },
        { value: 'quarantine', label: 'Quarantine' },
    ],
    warehouses: [{ id: '01WH', label: 'DHK · Dhaka' }],
};

function action(overrides: Partial<CaseAction> = {}): CaseAction {
    return {
        key: 'approve_inspection',
        label: 'Approve inspection',
        url: '/admin/cases/returns/01RET/inspection-approval',
        tone: 'primary',
        fields: {},
        confirmation: {
            items: [
                { name: 'Cotton panjabi', variant: 'Navy / M', quantity: 2 },
            ],
            responsible: 'partner',
            responsible_label: 'Partner',
            amounts: [
                {
                    key: 'return_charge',
                    amount: money('40.00'),
                    direction: 'debit',
                },
            ],
            shows_disposition: true,
            reason_required: false,
            reason_minimum: null,
        },
        ...overrides,
    };
}

const renderDialog = (
    overrides: Partial<CaseAction> = {},
    kind: 'return' | 'damage' = 'return',
) =>
    render(
        <CaseActionDialog
            action={action(overrides)}
            context={{ kind, items: [item], inspection }}
            open
            onClose={() => {}}
        />,
    );

describe('the confirmation of a case step', () => {
    it('restates what is about to happen, from the figures the server sent', () => {
        renderDialog();

        const summary = screen.getByRole('region', {
            name: 'cases.dialog.summary',
        });

        expect(summary).toHaveTextContent('Cotton panjabi');
        expect(summary).toHaveTextContent('Navy / M');
        expect(summary).toHaveTextContent('× 2');
        expect(summary).toHaveTextContent('Partner');
        expect(summary).toHaveTextContent('৳40.00');
        // What happens to the stock is said, as a line of its own.
        expect(summary).toHaveTextContent(
            'cases.dialog.stock.approve_inspection',
        );
    });

    it('posts to the route the server named, and asks for nothing more than the step needs', () => {
        renderDialog({
            key: 'start_inspection',
            url: '/admin/cases/returns/01RET/inspection-start',
            confirmation: {
                ...action().confirmation,
                amounts: [],
                shows_disposition: false,
            },
        });

        expect(screen.getByRole('form', { name: 'step' })).toHaveAttribute(
            'action',
            '/admin/cases/returns/01RET/inspection-start',
        );
        expect(screen.queryByRole('textbox')).toBeNull();
    });
});

describe('inspecting by quantity', () => {
    it('offers only what the backend can carry out, and never "dispatched" as a finding', () => {
        renderDialog();

        const options = screen
            .getAllByRole('option')
            .map((option) => option.textContent);

        expect(options).toContain('Sellable');
        expect(options).toContain('Damaged');
        expect(options).toContain('Quarantine');
        expect(options.join(' ')).not.toMatch(/dispatch|supplier/i);
    });

    it('refuses to send a count above what was approved, and says why', async () => {
        const user = userEvent.setup();
        renderDialog();

        const quantity = screen.getByRole('spinbutton');
        const confirm = screen.getByRole('button', {
            name: 'cases.dialog.confirm',
        });

        expect(quantity).toHaveValue(2);
        expect(confirm).toBeEnabled();

        await user.clear(quantity);
        await user.type(quantity, '3');

        expect(confirm).toBeDisabled();
        expect(screen.getByText('cases.fields.quantity_invalid')).toBeVisible();
    });

    it('refuses to send when nothing was counted, and a whole number only', async () => {
        const user = userEvent.setup();
        renderDialog();

        const quantity = screen.getByRole('spinbutton');
        const confirm = screen.getByRole('button', {
            name: 'cases.dialog.confirm',
        });

        await user.clear(quantity);
        await user.type(quantity, '0');
        expect(confirm).toBeDisabled();

        await user.clear(quantity);
        await user.type(quantity, '1.5');
        expect(confirm).toBeDisabled();

        await user.clear(quantity);
        await user.type(quantity, '1');
        expect(confirm).toBeEnabled();
    });
});

describe('deciding who bears a case', () => {
    it('is the same two choices carried by the server, with a reason that must be written', () => {
        renderDialog({
            key: 'change_responsibility',
            tone: 'default',
            fields: { party: 'banij', party_label: 'Banij' },
            confirmation: {
                ...action().confirmation,
                shows_disposition: false,
                reason_required: true,
                reason_minimum: 10,
            },
        });

        // The party is fixed by the server in a hidden field, never picked in the browser.
        expect(document.querySelector('input[name="party"]')).toHaveValue(
            'banij',
        );
        expect(document.querySelector('input[name="party_label"]')).toBeNull();

        const reason = screen.getByRole('textbox');
        expect(reason).toBeRequired();
        expect(reason).toHaveAttribute('minlength', '10');
        expect(screen.getByText('cases.dialog.reason_required')).toBeVisible();
    });
});
