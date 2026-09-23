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

const money = (minorUnits: number) => ({
    minor_units: minorUnits,
    currency: 'BDT',
    decimal: (minorUnits / 100).toFixed(2),
    formatted: `৳${(minorUnits / 100).toFixed(2)}`,
});

describe('supplier wallet dashboard', () => {
    it('shows the empty state when there is no wallet yet', () => {
        render(
            <SupplierWalletIndex
                wallet={null}
                payable_totals={{
                    pending: money(0),
                    eligible: money(0),
                    settled: money(0),
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
                    total: money(150000),
                    available: money(0),
                    reserved: money(150000),
                    recovery: money(150000),
                    has_outstanding_recovery: true,
                }}
                payable_totals={{
                    pending: money(0),
                    eligible: money(0),
                    settled: money(300000),
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
                    total: money(200000),
                    available: money(200000),
                    reserved: money(0),
                    recovery: money(0),
                    has_outstanding_recovery: false,
                }}
                payable_totals={{
                    pending: money(0),
                    eligible: money(0),
                    settled: money(200000),
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
