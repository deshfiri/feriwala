// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    supplier: {
        payout_methods: {
            title: 'Payout methods',
            description: 'Where your withdrawals are paid.',
            empty_title: 'No payout method yet',
            empty_description: 'Add a bank account, bKash or Nagad number.',
            add: 'Add payout method',
            edit: 'Edit',
            archive: 'Archive',
            default: 'Default',
            verified: 'Verified',
            not_verified: 'Not yet verified',
        },
    },
};

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
        Form: ({
            children,
        }: {
            children: (state: Record<string, unknown>) => unknown;
        }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        usePage: () => ({ props: { translations, locale: { current: 'en' } } }),
    };
});

const { default: SupplierPayoutMethodsIndex } = await import('./index');

const TYPES = [
    {
        value: 'bank_account',
        label: 'Bank account',
        fields: [
            'bank_name',
            'account_name',
            'account_number',
            'routing_number',
            'branch',
        ],
    },
    {
        value: 'bkash',
        label: 'bKash',
        fields: ['account_name', 'account_number'],
    },
    {
        value: 'nagad',
        label: 'Nagad',
        fields: ['account_name', 'account_number'],
    },
];

/**
 * A Supplier's payout methods list (D25, P13-24). The full account number is
 * never sent to the browser — the server only ever sends the masked form —
 * so the strongest assertion this page's own test can make is that nothing
 * resembling a real, unmasked account number ever reaches the DOM.
 */
describe('supplier payout methods', () => {
    it('shows only the masked number, and marks the default method', () => {
        render(
            <SupplierPayoutMethodsIndex
                methods={[
                    {
                        id: 'pm_1',
                        type: 'bkash',
                        type_label: 'bKash',
                        label: 'Primary bKash',
                        masked_number: '••••2222',
                        is_default: true,
                        is_active: true,
                        status_label: 'Active',
                        verified_at: null,
                        created_at: '2026-01-01T00:00:00Z',
                    },
                ]}
                types={TYPES}
            />,
        );

        expect(document.body.textContent).toContain('••••2222');
        expect(screen.getByText('Default')).toBeInTheDocument();
        expect(screen.getByText('Not yet verified')).toBeInTheDocument();

        // Nothing resembling a full, unmasked account number anywhere.
        expect(document.body.textContent).not.toMatch(/\b\d{6,}\b/);
    });

    it('never lists an archived method among the active ones', () => {
        render(
            <SupplierPayoutMethodsIndex
                methods={[
                    {
                        id: 'pm_1',
                        type: 'bkash',
                        type_label: 'bKash',
                        label: 'Old bKash',
                        masked_number: '••••1111',
                        is_default: false,
                        is_active: false,
                        status_label: 'Archived',
                        verified_at: null,
                        created_at: '2026-01-01T00:00:00Z',
                    },
                ]}
                types={TYPES}
            />,
        );

        expect(screen.queryByText('Old bKash')).not.toBeInTheDocument();
        expect(screen.getByText('No payout method yet')).toBeInTheDocument();
    });

    it('offers to add a payout method when there are none', () => {
        render(<SupplierPayoutMethodsIndex methods={[]} types={TYPES} />);

        expect(
            screen.getByRole('button', { name: 'Add payout method' }),
        ).toBeInTheDocument();
    });
});
