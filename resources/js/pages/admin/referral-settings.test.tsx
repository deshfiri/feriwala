// @vitest-environment jsdom

import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { PlanVersion } from './referral-settings';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url },
                children as never,
            ),
        Form: ({
            action,
            children,
        }: {
            action: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, method: 'post' },
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: ReferralSettings } = await import('./referral-settings');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

const version: PlanVersion = {
    id: '01PLAN',
    package: null,
    trigger: 'account_activation',
    trigger_label: 'Account activation after verified payment',
    base: 'activation_fees',
    base_label: 'Registration and package fees',
    max_depth: 3,
    levels: [
        {
            level: 1,
            reward: {
                type: 'percentage',
                amount: null,
                percent: '10',
                cap: null,
            },
            enabled: true,
            required_packages: [],
            min_active_direct_referrals: 0,
        },
        {
            level: 2,
            reward: {
                type: 'percentage',
                amount: null,
                percent: '5',
                cap: null,
            },
            enabled: false,
            required_packages: [],
            min_active_direct_referrals: 0,
        },
        {
            level: 3,
            reward: {
                type: 'fixed',
                amount: money(10000),
                percent: null,
                cap: null,
            },
            enabled: true,
            required_packages: ['Growth'],
            min_active_direct_referrals: 2,
        },
    ],
    joining_reward: null,
    holding_days: 7,
    minimum_qualifying_payment: money(0),
    qualifies: {
        suspended: false,
        restricted: true,
        package_lapsed: false,
        not_active: false,
    },
    state: 'in_force',
    effective_from: '2026-09-19T10:00:00+06:00',
    effective_to: null,
    reason: 'Launching the three-level plan.',
    opened_by: 'Rafi Referral Manager',
    close_reason: null,
    closed_by: null,
};

const paginator = (data: PlanVersion[]) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 10,
    from: data.length > 0 ? 1 : null,
    to: data.length,
    total: data.length,
});

const options = {
    packages: [{ value: '01PACKAGE', label: 'Growth' }],
    triggers: [{ value: 'account_activation', label: 'Account activation' }],
    bases: [
        { value: 'activation_fees', label: 'Registration and package fees' },
    ],
};

describe('referral settings', () => {
    it('shows each version level by level, with disabled levels and conditions said in words', () => {
        render(
            <ReferralSettings
                enabled={false}
                can_manage={false}
                plans={paginator([version])}
                {...options}
            />,
        );

        expect(screen.getByText('10%')).toBeInTheDocument();
        expect(screen.getByText('5%')).toBeInTheDocument();
        expect(screen.getByText('৳100.00')).toBeInTheDocument();
        expect(
            screen.getByText('referral.settings.disabled_level'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('referral.settings.min_direct_short'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Launching the three-level plan.'),
        ).toBeInTheDocument();
    });

    it('offers no form to change anything to someone who may only see it', () => {
        render(
            <ReferralSettings
                enabled={false}
                can_manage={false}
                plans={paginator([version])}
                {...options}
            />,
        );

        expect(screen.getByRole('note')).toHaveTextContent(
            'referral.settings.read_only',
        );
        expect(
            screen.queryByRole('button', { name: 'referral.settings.open' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'referral.settings.switch_on',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'referral.settings.close' }),
        ).not.toBeInTheDocument();
    });

    it('asks for a rule for every level up to the depth, and the right field for each kind', () => {
        render(
            <ReferralSettings
                enabled
                can_manage
                plans={paginator([])}
                {...options}
            />,
        );

        expect(screen.getAllByTestId('level-row')).toHaveLength(3);

        act(() => {
            fireEvent.change(
                screen.getByLabelText(/referral\.settings\.max_depth/),
                { target: { value: '5' } },
            );
        });

        const rows = screen.getAllByTestId('level-row');

        expect(rows).toHaveLength(5);
        expect(
            rows[4].querySelector('input[name="levels[4][rate_percent]"]'),
        ).not.toBeNull();

        act(() => {
            fireEvent.change(
                screen.getByLabelText(/referral.settings.type/, {
                    selector: '#level-4-type',
                }),
                { target: { value: 'fixed' } },
            );
        });

        expect(
            rows[4].querySelector('input[name="levels[4][amount_minor]"]'),
        ).not.toBeNull();
        expect(
            rows[4].querySelector('input[name="levels[4][rate_percent]"]'),
        ).toBeNull();
        expect(
            screen.getByRole('button', {
                name: 'referral.settings.switch_off',
            }),
        ).toBeInTheDocument();
    });
});
