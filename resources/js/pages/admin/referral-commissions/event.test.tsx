// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { EventDetail } from './event';

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

const { default: ReferralEvent } = await import('./event');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

const commission = (level: number, status: string, canReverse: boolean) => ({
    id: `01COMMISSION${level}`,
    event: '01EVENT',
    trigger_label: 'Account activation after verified payment',
    source: { id: '01SOURCE', name: 'Tania Textiles' },
    beneficiary: {
        id: `01BENEFICIARY${level}`,
        name: level === 1 ? 'Sohel Supplies' : 'Nadia Naturals',
    },
    level,
    is_joining_reward: false,
    amount: money(level === 1 ? 65000 : 0),
    rule: {
        type: 'percentage' as const,
        percent: level === 1 ? '10' : '5',
        amount: null,
    },
    status,
    status_label: status,
    skip_reason: status === 'skipped' ? 'This level does not pay' : null,
    capped: false,
    available_at: '2026-09-19T10:00:00+06:00',
    paid_at: status === 'paid' ? '2026-09-19T10:00:00+06:00' : null,
    created_at: '2026-09-19T10:00:00+06:00',
    reversal: null,
    can_reverse: canReverse,
});

const event: EventDetail = {
    id: '01EVENT',
    trigger_label: 'Account activation after verified payment',
    occurred_at: '2026-09-19T10:00:00+06:00',
    status: 'recorded',
    source: { id: '01SOURCE', name: 'Tania Textiles' },
    payment: 'PAY-260919-ABCDEFGH',
    base: money(650000),
    plan: {
        id: '01PLAN',
        max_depth: 3,
        effective_from: '2026-09-18T10:00:00+06:00',
    },
    chain: [
        {
            level: 1,
            account: { id: '01B1', name: 'Sohel Supplies' },
            outcome: 'pending',
            outcome_label: 'Paid or waiting to be paid',
        },
        {
            level: 2,
            account: { id: '01B2', name: 'Nadia Naturals' },
            outcome: 'level_disabled',
            outcome_label: 'This level does not pay',
        },
        {
            level: 3,
            account: null,
            outcome: 'missing',
            outcome_label: 'No account at this level',
        },
    ],
    reversed_at: null,
    reversal_reason: null,
    commissions: [commission(1, 'paid', true), commission(2, 'skipped', false)],
};

const causes = [{ value: 'fraud', label: 'Fraud or abuse' }];

describe('a qualifying event', () => {
    it('shows every level the plan asked about, including the ones that did not pay', () => {
        render(
            <ReferralEvent event={event} can_reverse={false} causes={causes} />,
        );

        expect(
            screen.getByText('This level does not pay', { selector: 'span' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('No account at this level'),
        ).toBeInTheDocument();
        expect(screen.getByText('PAY-260919-ABCDEFGH')).toBeInTheDocument();
    });

    it('offers reversal only to someone who may, and only for commissions that can be taken back', () => {
        const { unmount } = render(
            <ReferralEvent event={event} can_reverse={false} causes={causes} />,
        );

        expect(
            screen.queryByRole('button', {
                name: 'referral.event.reverse_one',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'referral.event.reverse_all',
            }),
        ).not.toBeInTheDocument();

        unmount();
        render(
            <ReferralEvent
                event={event}
                can_reverse
                password_confirmed
                causes={causes}
            />,
        );

        expect(
            screen.getAllByRole('button', {
                name: 'referral.event.reverse_one',
            }),
        ).toHaveLength(1);
        expect(
            screen.getByRole('button', { name: 'referral.event.reverse_all' }),
        ).toBeInTheDocument();
    });

    it('asks for the password first, instead of the forms, until it is confirmed', () => {
        render(
            <ReferralEvent
                event={event}
                can_reverse
                password_confirmed={false}
                causes={causes}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'referral.event.confirm' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'referral.event.reverse_one',
            }),
        ).not.toBeInTheDocument();
    });
});
