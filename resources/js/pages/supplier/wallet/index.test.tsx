// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    supplier: {
        wallet: {
            title: 'Wallet',
            description: 'What has settled into your wallet.',
            no_wallet_title: 'No wallet yet',
            no_wallet_description: 'A wallet opens once a payable settles.',
            total: 'Total balance',
            available: 'Available balance',
            reserved: 'Reserved for withdrawal',
            recovery: 'Outstanding recovery',
            recovery_warning: 'Held against future settlements until cleared.',
            payable_totals: 'Payables',
            pending_total: 'Pending',
            eligible_total: 'Eligible',
            settled_total: 'Settled',
            recent_activity: 'Recent activity',
            view_all_transactions: 'View all transactions',
            empty_activity: 'No activity yet.',
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
        usePage: () => ({ props: { translations, locale: { current: 'en' } } }),
    };
});

const { default: SupplierWalletIndex } = await import('./index');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

describe('supplier wallet dashboard', () => {
    it('shows the empty state when there is no wallet yet', () => {
        render(
            <SupplierWalletIndex
                wallet={null}
                payable_totals={{
                    pending: money('0.00'),
                    eligible: money('0.00'),
                    settled: money('0.00'),
                }}
                recent_entries={[]}
            />,
        );

        expect(screen.getByText('No wallet yet')).toBeInTheDocument();
    });

    it('warns about an outstanding recovery only when there is one', () => {
        render(
            <SupplierWalletIndex
                wallet={{
                    id: 'wlt_1',
                    currency: 'BDT',
                    total: money('1500.00'),
                    available: money('0.00'),
                    reserved: money('1500.00'),
                    recovery: money('1500.00'),
                    has_outstanding_recovery: true,
                }}
                payable_totals={{
                    pending: money('0.00'),
                    eligible: money('0.00'),
                    settled: money('3000.00'),
                }}
                recent_entries={[]}
            />,
        );

        expect(
            screen.getByText('Held against future settlements until cleared.'),
        ).toBeInTheDocument();
    });

    it('shows no recovery warning for a clean wallet', () => {
        render(
            <SupplierWalletIndex
                wallet={{
                    id: 'wlt_1',
                    currency: 'BDT',
                    total: money('2000.00'),
                    available: money('2000.00'),
                    reserved: money('0.00'),
                    recovery: money('0.00'),
                    has_outstanding_recovery: false,
                }}
                payable_totals={{
                    pending: money('0.00'),
                    eligible: money('0.00'),
                    settled: money('2000.00'),
                }}
                recent_entries={[]}
            />,
        );

        expect(
            screen.queryByText(
                'Held against future settlements until cleared.',
            ),
        ).not.toBeInTheDocument();
    });
});
